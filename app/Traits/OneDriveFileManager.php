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
use Illuminate\Http\Request;

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

    /** List files in a OneDrive folder */
    public function listOneDriveFiles($parentId = null)
    {
        $token = $this->oneDrive()->getAccessToken();
        $userPrincipalName = config('services.microsoft.storage_user');
        $parent = $parentId ? File::find($parentId) : null;
        $parentOneDriveId = $parent?->onedrive_file_id;

        $url = $parentOneDriveId
            ? "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$parentOneDriveId}/children"
            : "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/root/children";

        $response = Http::withToken($token)->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to list OneDrive files: " . $response->body());
        }

        $items = $response->json()['value'] ?? [];

        $mapped = collect($items)->map(function ($item) {
            return [
                'id' => $item['id'],
                'name' => $item['name'],
                'type' => isset($item['folder']) ? 'folder' : 'file',
                'size' => $item['size'] ?? 0,
                'webUrl' => $item['webUrl'] ?? null,

            ];
        });

        // 🔑 Check permissions from DB
        $dbFiles = File::whereIn('onedrive_file_id', $mapped->pluck('id'))->get();

        $userFiles = $dbFiles->filter(fn($file) => $this->checkPermission($file, 'view'));

        return $userFiles->values();
    }

    /** Create a folder in OneDrive & record in DB */
    public function createOneDriveFolder($name, $parentId = null)
    {
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

        FileHistory::create([
            'file_id' => $file->id,
            'user_id' => $this->logedInUser->id,
            'action' => 'create_folder',
            'metadata' => ['onedrive_id' => $data['id']],
        ]);

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

        FileHistory::create([
            'file_id'  => $file->id,
            'user_id'  => $this->logedInUser->id,
            'action'   => 'upload',
            'metadata' => ['onedrive_id' => $data['id']],
        ]);

        return $file;
    }

    /** Delete item */
    public function deleteOneDriveItem($fileId)
    {
        $file = File::find($fileId);
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
        FileHistory::create([
            'file_id'  => $file->id,
            'user_id'  => $this->logedInUser->id,
            'action'   => 'delete',
            'metadata' => ['name' => $file->name],
        ]);

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

            FileHistory::create([
                'file_id'  => $file->id,
                'user_id'  => $this->logedInUser->id,
                'action'   => 'move',
                'metadata' => ['new_parent_id' => $newParentId],
            ]);

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
}
