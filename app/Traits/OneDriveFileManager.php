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
    /**
     * Notify + log any file action
     */
    protected function finalizeFileAction(File $file, string $action, array $metadata = [])
    {
        $this->logFileAction($file->id, $action, $this->logedInUser->id, $metadata);
        $this->logedInUser->notify(new FileActionNotification($action, $file));
    }

    protected function ensureUserRoot($userId = null)
    {
        $user = $userId ? \App\Models\User::find($userId) : $this->logedInUser;
        if (!$user) {
            throw new \Exception("User not found");
        }
        $root = File::where('user_id', $user->id)->whereNull('parent_id')->where('type', 'folder')->first();
        if ($root) {
            return $root;
        }
        $rootName = "UserRoot_" . $user->id;
        $data = $this->oneDrive()->createFolder($rootName);
        $root = $this->createFileRecord($userId, $data, null, 'folder');
        $this->grantPermission($root, $userId, 'owner');
        $this->logFileAction($root->id, 'create_root', $user->id, ['onedrive_file_id' => $data['id']]);
        $user->notify(new FileActionNotification('root_created', $root));

        return $root;
    }

    /** List files in a OneDrive folder */
    public function listOneDriveFiles($parentId = null, $userId = null)
    {
        $userId = $userId ?? $this->logedInUser->id;
        if (!$parentId) {
            $root = $this->ensureUserRoot($userId);
            $parentId = $root->id;
        }
        $fileIds = FilePermission::where('user_id', $userId)->whereIn('permission', ['owner', 'view'])->pluck('file_id');
        $files = File::whereIn('id', $fileIds)->where('is_trashed', false)->where('parent_id', $parentId)->get();
        // Add is_starred property
        $starredIds = Auth::guard('api')->user()
            ->starredFiles()
            ->pluck('file_id')
            ->toArray();

        $files->map(function ($file) use ($starredIds) {
            $file->is_starred = in_array($file->id, $starredIds);
            return $file;
        });
        return $files;
    }
    public function createOneDriveFolder($name, $parentId = null)
    {
        $userId = $this->logedInUser->id;
        if (!$parentId) {
            $root = $this->ensureUserRoot($userId);
            $parentId = $root->id;
        }
        $dbFile = File::find($parentId);
        if (!$dbFile || !$this->checkPermission($dbFile, 'create_folder')) {
            return ['status' => 'error', 'message' => 'Permission denied for this folder'];
        }
        $data = $this->oneDrive()->createFolder($name, $dbFile->onedrive_file_id);
        $file = $this->createFileRecord($userId, $data, $parentId, 'folder');
        $this->finalizeFileAction($file, 'create_folder', ['onedrive_file_id' => $data['id']]);

        return $file;
    }


    public function uploadFileToOneDrive($parentId, $uploadedFile)
    {
        $userId = $this->logedInUser->id;
        $parent = $parentId ? File::find($parentId) : null;
        if ($parentId && (!$parent || !$this->checkPermission($parent, 'upload'))) {
            return ['status' => 'error', 'message' => 'Permission denied'];
        }
        $data = $this->oneDrive()->uploadFile($uploadedFile, $parent?->onedrive_file_id);
        $file = $this->createFileRecord($userId, $data, $parent?->id, 'file');
        $this->finalizeFileAction($file, 'uploaded', ['onedrive_file_id' => $data['id']]);

        return $file;
    }


    /** Delete item */
    public function deleteOneDriveItem($fileId)
    {
        $file = File::withTrashed()->find($fileId);
        if (!$file) return ['status' => 'error', 'message' => 'File not found'];
        if (!$this->checkPermission($file, 'delete')) {
            return ['status' => 'error', 'message' => 'Permission denied'];
        }
        $this->oneDrive()->delete($file->onedrive_file_id);
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
            $data = $this->oneDrive()->move($file->onedrive_file_id, $newParent?->onedrive_file_id);
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
        $file = File::find($fileId);
        if (!$file) {
            return response()->json(['status' => 'error', 'message' => 'File not found'], 404);
        }
        $this->oneDrive()->rename($file->onedrive_file_id, $name);
        $file->update(['name' => $name]);
        $this->finalizeFileAction($file, 'rename', ['new_name' => $name]);
        return response()->json([
            'status' => 'ok',
            'message' => 'File renamed successfully',
            'file' => $file,
        ]);
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
                    $this->grantPermission($file, $this->logedInUser->id, 'owner');
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

    public function trashBulkFiles($file_ids)
    {
        $userId = $userId ?? $this->logedInUser->id;
        $allIds = $this->getAllChildrenIds($file_ids);
        File::whereIn('id', $allIds)->where('user_id', $userId)->update(['is_trashed' => true]);
        return response()->json([
            'status' => 'ok',
            'message' => 'Files (with children) moved to trash successfully'
        ]);
    }
    public function bulkRestoreFiles($file_ids)
    {
        $userId = $userId ?? $this->logedInUser->id;
        $allIds = $this->getAllChildrenIds($file_ids);
        File::whereIn('id', $allIds)->where('user_id', $userId)->update(['is_trashed' => false]);
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
        $file->update(['is_trashed' => true]);
    }
    public function listTrashedFiles($userId = null)
    {
        $userId = $userId ?? $this->logedInUser->id;
        $fileIds = FilePermission::where('user_id', $userId)
            ->whereIn('permission', ['owner', 'view'])
            ->pluck('file_id');
        $trashed = File::where('is_trashed', true)->whereIn('id', $fileIds)->get();
        return $trashed;
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
        $file->update(['is_trashed' => false]);
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
