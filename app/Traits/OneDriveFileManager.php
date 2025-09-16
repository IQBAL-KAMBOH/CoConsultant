<?php

namespace App\Traits;

use App\Models\File;
use App\Models\FilePermission;
use App\Models\FileHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Traits\FilePermissionHelper;
use App\Services\OneDriveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Exception;
use App\Notifications\FileActionNotification;
use Illuminate\Http\Request;
use Carbon\Carbon;

trait OneDriveFileManager
{
    use FilePermissionHelper;

    protected $logedInUser;


    public function __construct()
    {
        $this->logedInUser = Auth::guard('api')->user();
        if (!$this->logedInUser) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }
    }

    protected function oneDrive(): OneDriveService
    {
        return new OneDriveService();
    }

    public function logFileAction($fileId, $action, $userId, $metadata = [])
    {
        $today = Carbon::today();

        $existingHistory = FileHistory::where('file_id', $fileId)
            ->where('user_id', $userId)
            ->where('action', $action)
            ->whereDate('created_at', $today)
            ->first();

        if ($existingHistory) {
            // Update metadata and timestamp instead of creating duplicate
            $existingHistory->update([
                'metadata' => $metadata ? json_encode($metadata) : $existingHistory->metadata,
                'updated_at' => now(),
            ]);
        } else {
            // Insert new history
            FileHistory::create([
                'file_id'  => $fileId,
                'user_id'  => $userId,
                'action'   => $action, // e.g. 'view', 'upload', 'download'
                'metadata' => $metadata ? json_encode($metadata) : null,
            ]);
        }
    }


    /** List files in a OneDrive folder */
    public function listOneDriveFiles($parentId = null, $userId = null)
    {


        // If no parentId, fetch the root folder record
        if (!$parentId && !$userId) {
            $root = File::where('name', 'root')
                ->whereNull('parent_id') // root has no parent
                ->first();

            $parentId = $root?->id; // safe null check
        }

        $userId = $userId ?? $this->logedInUser->id;
        $isAdmin = $this->logedInUser->hasRole('admin');
        // Get file IDs where user has view/owner permission
        $fileIds = FilePermission::where('user_id', $userId)
            ->whereIn('permission', ['owner', 'view'])
            ->pluck('file_id')->toArray();;
        // If parentId is given, check accessibility
        if ($parentId) {
            $parentFile = File::find($parentId);

            if ($parentFile) {
                // Admin can always access, others must have permission
                if (in_array($parentFile->id, $fileIds)) {
                    // Log history
                    $this->logFileAction($parentFile->id, 'view', $userId, [
                        'ip'    => request()->ip(),
                        'agent' => request()->userAgent(),
                    ]);
                } else {
                    // If not accessible, return empty
                    return collect();
                }
            }
        }
        $files = File::whereIn('id', $fileIds)
            ->when($parentId, fn($q) => $q->where('parent_id', $parentId))
            ->get();

        return $files;
    }

    /** Create a folder in OneDrive & record in DB */
    public function createOneDriveFolder($name, $parentId = null)
    {
        // ✅ If no parent folder → check role
        if (!$parentId) {
            if (!$this->logedInUser->hasRole('admin')) {
                return [
                    'status'  => 'error',
                    'message' => 'Only admins can create folders in root'
                ];
            }
        } else {
            // ✅ If creating inside a folder, check permission
            $dbFile = File::find($parentId);
            if (!$this->checkPermission($dbFile, 'create_folder')) {
                return [
                    'status'  => 'error',
                    'message' => 'Permission denied for this folder'
                ];
            }
        }
        $token = $this->oneDrive()->getAccessToken();
        $userPrincipalName = config('services.microsoft.storage_user');

        $body = [
            'name' => $name,
            'folder' => new \stdClass(),
            '@microsoft.graph.conflictBehavior' => 'rename',
        ];

        $parent = $parentId ? File::find($parentId) : null;
        $parentOneDriveId = $parent?->onedrive_file_id;


        $url = $parentOneDriveId
            ? "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$parentOneDriveId}/children"
            : "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/root/children";

        $response = Http::withToken($token)->post($url, $body);

        if ($response->failed()) {
            throw new \Exception("Failed to create folder: " . $response->body());
        }

        $data = $response->json();

        $file = File::create([
            'user_id'         => $this->logedInUser->id,
            'name'            => $data['name'],
            'type'            => 'folder',
            'path'            => $data['parentReference']['path'] . '/' . $data['name'], // human readable path
            'onedrive_file_id' => $data['id'], // Graph ID
            'size'            => 0,
            'storage_type'    => 'onedrive',
            'parent_id'       => $parentId ?? null,
            'web_url'         => $data['webUrl'] ?? null,
        ]);

        FilePermission::create([
            'file_id' => $file->id,
            'user_id' => $this->logedInUser->id,
            'permission' => 'owner',
        ]);

        $this->logFileAction($file->id, 'create_folder', $this->logedInUser->id, ['onedrive_file_id' => $data['id']]);

        $this->logedInUser->notify(new FileActionNotification('creatd', $file));


        return $file;
    }

    /** Upload file */
    public function uploadFileToOneDrive($parentId, $uploadedFile)
    {
        $token = $this->oneDrive()->getAccessToken();
        $userPrincipalName = config('services.microsoft.storage_user');

        $parent = $parentId ? File::find($parentId) : null;
        $parentOneDriveId = $parent?->onedrive_file_id;

        $path = $parentOneDriveId
            ? "/items/{$parentOneDriveId}:/{$uploadedFile->getClientOriginalName()}:/content"
            : "/root:/{$uploadedFile->getClientOriginalName()}:/content";

        $response = Http::withToken($token)
            ->withBody(
                file_get_contents($uploadedFile->getRealPath()),
                $uploadedFile->getMimeType() // e.g. "application/pdf"
            )
            ->put("https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive{$path}");

        if ($response->failed()) {
            throw new \Exception("Failed to upload file: " . $response->body());
        }

        $data = $response->json();

        $file = File::create([
            'user_id'         => $this->logedInUser->id,
            'name'            => $data['name'],
            'type'            => 'file',
            'path'            => $data['parentReference']['path'] . '/' . $data['name'],
            'onedrive_file_id' => $data['id'],
            'size'            => $uploadedFile->getSize(),
            'parent_id'       => $parent?->id,
            'storage_type'    => 'onedrive',
            'web_url'         => $data['webUrl'] ?? null,
            'download_url' => $data['@microsoft.graph.downloadUrl']
        ]);

        FilePermission::create([
            'file_id'    => $file->id,
            'user_id'    => $this->logedInUser->id,
            'permission' => 'owner',
        ]);

        $this->logFileAction($file->id, 'uploaded', $this->logedInUser->id, ['onedrive_file_id' => $data['id']]);
        $this->logedInUser->notify(new FileActionNotification('uploaded', $file));

        return $file;
    }

    /** Delete item */
    public function deleteOneDriveItem($fileId)
    {
        $file = File::withTrashed()->find($fileId);
        if (!$file) return ['status' => 'error', 'message' => 'File not found'];

        // 🔑 Check delete permission
        if (!$this->checkPermission($file, 'delete')) {
            return ['status' => 'error', 'message' => 'Permission denied'];
        }

        $token = $this->oneDrive()->getAccessToken();
        $userPrincipalName = config('services.microsoft.storage_user');

        $response = Http::withToken($token)
            ->delete("https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$file->onedrive_file_id}");

        if ($response->failed()) {
            throw new \Exception("Failed to delete OneDrive item: " . $response->body());
        }

        $this->deleteFileAndChildren($file);

        return ['status' => 'success'];
    }


    protected function deleteFileAndChildren($file)
    {
        $children = File::where('parent_id', $file->id)->get();
        foreach ($children as $child) {
            $this->deleteFileAndChildren($child);
        }
        FilePermission::where('file_id', $file->id)->delete();
        $this->logFileAction($file->id, 'delete', $this->logedInUser->id, ['name' => $file->name]);
        $this->logedInUser->notify(new FileActionNotification('deleted', $file));

        $file->delete();
    }
    public function syncOneDrive()
    {
        try {
            // Load last saved deltaLink (store this in DB or cache)
            $deltaLink = cache()->get('onedrive_delta');
            $response  = $this->oneDrive()->syncDrive($deltaLink);

            $syncedCount = 0;
            DB::beginTransaction();

            foreach ($response['value'] as $item) {
                try {
                    if (isset($item['deleted'])) {
                        File::where('onedrive_file_id', $item['id'])->delete();
                        continue;
                    }

                    $isFolder = isset($item['folder']);
                    $parentId = null;

                    if (!empty($item['parentReference']['id'])) {
                        $parent   = File::where('onedrive_file_id', $item['parentReference']['id'])->first();
                        $parentId = $parent?->id;
                    }

                    $parentPath = $item['parentReference']['path'] ?? null;
                    $fullPath   = $parentPath
                        ? $parentPath . '/' . $item['name']
                        : $item['name'];

                    $file = File::updateOrCreate(
                        ['onedrive_file_id' => $item['id']],
                        [
                            'user_id'      => $this->logedInUser->id,
                            'name'         => $item['name'],
                            'type'         => $isFolder ? 'folder' : 'file',
                            'path'         => $fullPath,
                            'size'         => $item['size'] ?? 0,
                            'storage_type' => 'onedrive',
                            'parent_id'    => $parentId,
                            'web_url'      => $item['webUrl'] ?? null,
                            'download_url' => $isFolder ? null : ($item['@microsoft.graph.downloadUrl'] ?? null),
                        ]
                    );

                    FilePermission::firstOrCreate(
                        ['file_id' => $file->id, 'user_id' => $this->logedInUser->id],
                        ['permission' => 'owner']
                    );
                    $this->logedInUser->notify(new FileActionNotification('synched', $file));

                    $syncedCount++;
                } catch (Exception $e) {
                    Log::error('OneDrive sync item failed', [
                        'error' => $e->getMessage(),
                        'item'  => $item,
                    ]);
                }
            }

            if (isset($response['@odata.deltaLink'])) {
                cache()->put('onedrive_delta', $response['@odata.deltaLink']);
            }

            DB::commit();

            return response()->json([
                'status'  => 'ok',
                'message' => 'OneDrive sync completed',
                'synced'  => $syncedCount,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('OneDrive sync failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'OneDrive sync failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    public function moveOneDrive($fileId, $newParentId = null)
    {
        try {
            $file = File::find($fileId);
            if (!$file) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'File not found in DB',
                ], 404);
            }

            $newParent = $newParentId ? File::find($newParentId) : null;
            if ($newParentId && !$newParent) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'New parent folder not found in DB',
                ], 404);
            }

            $token = $this->oneDrive()->getAccessToken();
            $userPrincipalName = config('services.microsoft.storage_user');

            // OneDrive move API → PATCH parentReference
            $url = "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$file->onedrive_file_id}";
            $body = [
                'parentReference' => [
                    'id' => $newParent?->onedrive_file_id ?? null,
                ],
            ];

            $response = Http::withToken($token)->patch($url, $body);

            if ($response->failed()) {
                throw new \Exception("OneDrive move failed: " . $response->body());
            }

            $data = $response->json();

            // Update in DB
            $file->update([
                'parent_id' => $newParent?->id,
                'path'      => ($data['parentReference']['path'] ?? '') . '/' . $data['name'],
                'web_url'   => $data['webUrl'] ?? $file->web_url,
            ]);
            $this->logedInUser->notify(new FileActionNotification('moved', $file));
            $this->logFileAction($file->id, 'move', $this->logedInUser->id, ['new_parent_id' => $newParentId]);
            return response()->json([
                'status' => 'success',
                'message' => 'File moved successfully',
                'file' => $file,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Move failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function renameOneDriveFile($fileId, $name)
    {
        try {
            $file = File::find($fileId);
            if (!$file) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'File not found in DB',
                ], 404);
            }

            // 1. Rename in OneDrive
            $oneDriveResponse = $this->oneDrive()->rename($file->onedrive_file_id, $name);

            // 2. Update local DB
            $file->name = $name;
            $file->save();

            $this->logFileAction($file->id, 'rename', $this->logedInUser->id, ['new_name' => $name]);
            $this->logedInUser->notify(new FileActionNotification('renamed', $file));
            return response()->json([
                'status' => 'ok',
                'message' => 'File renamed successfully',
                'file' => $file,
                'onedrive' => $oneDriveResponse
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Rename failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function handleFileDownloadUrl($fileId)
    {
        try {
            $file = File::find($fileId);
            if (!$file) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'File not found',
                ], 404);
            }

            // ✅ check permission if you want
            if (!$this->checkPermission($file, 'view')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permission denied',
                ], 403);
            }

            $downloadUrl = $this->oneDrive()->getDownloadUrl($file->onedrive_file_id);

            if (!$downloadUrl) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Could not generate download URL',
                ], 500);
            }
            $this->logFileAction($file->id, 'downloaded', $this->logedInUser->id, ['name' => $file->name]);
            $this->logedInUser->notify(new FileActionNotification('downloaded', $file));
            return response()->json([
                'status' => 'success',
                'download_url' => $downloadUrl,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Exception while generating download URL',
                'error' => $e->getMessage(),
            ], 500);
        }
    }






    //////// Trash


    public function trashFile($fileId)
    {
        $file = File::find($fileId);
        if (!$file) {
            return response()->json(['status' => 'error', 'message' => 'File not found'], 404);
        }

        if (!$this->checkPermission($file, 'delete')) {
            return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
        }

        DB::transaction(function () use ($file) {
            $this->softDeleteRecursive($file);
        });

        $this->logFileAction($file->id, 'trash', $this->logedInUser->id, ['name' => $file->name]);

        return response()->json(['status' => 'success', 'message' => 'File moved to trash']);
    }
    private function getAllChildrenIds($fileIds)
    {
        $allIds = collect($fileIds);

        $children = File::whereIn('parent_id', $fileIds)->pluck('id');

        if ($children->isNotEmpty()) {
            $allIds = $allIds->merge($this->getAllChildrenIds($children));
        }

        return $allIds;
    }

    // ✅ Bulk Trash (Soft Delete + Children)
    public function trashBulkFiles($file_ids)
    {
        $userId = $userId ?? $this->logedInUser->id;
        $allIds = $this->getAllChildrenIds($file_ids);
        File::whereIn('id', $allIds)
            ->where('user_id', $userId)
            ->delete(); // Soft delete
        return response()->json([
            'status' => 'ok',
            'message' => 'Files (with children) moved to trash successfully'
        ]);
    }
    public function bulkRestoreFiles($file_ids)
    {

        $userId = $userId ?? $this->logedInUser->id;
        $allIds = $this->getAllChildrenIds($file_ids);

        File::withTrashed()
            ->whereIn('id', $allIds)
            ->where('user_id', $userId)
            ->restore();

        return response()->json([
            'status' => 'ok',
            'message' => 'Files (with children) restored successfully'
        ]);
    }
    /** Recursive soft delete */
    protected function softDeleteRecursive(File $file)
    {
        $children = File::where('parent_id', $file->id)->get();
        foreach ($children as $child) {
            $this->softDeleteRecursive($child);
        }
        $this->logedInUser->notify(new FileActionNotification('trashed', $file));
        $file->delete(); // soft delete
    }



    public function listTrashedFiles($userId = null)
    {
        $userId = $userId ?? $this->logedInUser->id;

        $fileIds = FilePermission::where('user_id', $userId)
            ->whereIn('permission', ['owner', 'view'])
            ->pluck('file_id');

        $trashed = File::onlyTrashed()->whereIn('id', $fileIds)->get();

        return response()->json([
            'status' => 'success',
            'files'  => $trashed,
        ]);
    }




    /////////////////////////////  Restore

    public function restoreFile($fileId)
    {
        $file = File::withTrashed()->find($fileId);
        if (!$file) {
            return response()->json(['status' => 'error', 'message' => 'File not found'], 404);
        }

        if (!$this->checkPermission($file, 'restore')) {
            return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
        }

        DB::transaction(function () use ($file) {
            $this->restoreRecursive($file);
        });
        $this->logFileAction($file->id, 'restore', $this->logedInUser->id, ['name' => $file->name]);
        return response()->json(['status' => 'success', 'message' => 'File restored successfully']);
    }

    /** Recursive restore */
    protected function restoreRecursive(File $file)
    {
        $file->restore();
        $this->logedInUser->notify(new FileActionNotification('restored', $file));

        $children = File::withTrashed()->where('parent_id', $file->id)->get();
        foreach ($children as $child) {
            $this->restoreRecursive($child);
        }
    }
    public function getStorageUsage()
    {
        return $this->oneDrive()->getStorageUsage();
    }

    public function getRecentFiles()
    {
        $user = Auth::guard('api')->user();

        $histories = FileHistory::with('file')
            ->where('user_id', $user->id)
            ->where('action', 'view')
            ->orderByDesc('updated_at')
            ->take(10)
            ->get();


        return response()->json([
            'status' => 'ok',
            'recent_views' => $histories->map(function ($history) {
                return [
                    'file_id'    => $history->file->id,
                    'name'       => $history->file->name,
                    'type'       => $history->file->type,
                    'path'       => $history->file->path,
                    'viewed_at'  => $history->created_at->toDateTimeString(),
                ];
            }),
        ]);
    }
}
