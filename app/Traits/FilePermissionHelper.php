<?php

namespace App\Traits;

use App\Models\File;
use App\Models\FileHistory;
use App\Models\FilePermission;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

trait FilePermissionHelper
{
    protected function checkPermission($file, $action)
    {
        $user = Auth::guard('api')->user();

        if (!$file) {
            return false;
        }

        // Owner check
        $hasOwnerPermission = FilePermission::where('file_id', $file->id)
            ->where('user_id', $user->id)
            ->where('permission', 'owner')
            ->exists();


        if ($file->user_id === $user->id && $hasOwnerPermission) {
            return true;
        }

        // Check mapped permissions
        $permission = FilePermission::where('file_id', $file->id)
            ->where('user_id', $user->id)
            ->where('permission', $action)
            ->first();

        $map = [
            'view'          => ['owner', 'view'],
            'upload'        => ['owner', 'upload'],
            'create_folder' => ['owner', 'create_folder'],
            'edit'          => ['owner', 'edit'],
            'delete'        => ['owner', 'delete'],
        ];

        return $permission && in_array($permission->permission, $map[$action]);
    }
    public function grantPermission(File $file, int $userId, string $permission = 'view'): FilePermission
    {
        return FilePermission::updateOrCreate(
            ['file_id' => $file->id, 'user_id' => $userId],
            ['permission' => $permission]
        );
    }

    /**
     * Revoke a user's permission on a file
     */
    public function revokePermission(File $file, int $userId): bool
    {
        return (bool) FilePermission::where('file_id', $file->id)
            ->where('user_id', $userId)
            ->delete();
    }
    /**
     * Create a File record in DB and assign owner permissions.
     */
    protected function createFileRecord($userId, $data, $parentId = null, $type = 'file')
    {
        $file = File::updateOrCreate(
            [
                // Uniqueness check: OneDrive ID
                'onedrive_file_id' => $data['id'],
            ],
            [
                'user_id'          => $userId,
                'name'             => $data['name'],
                'type'             => $type,
                'path'             => ($data['parentReference']['path'] ?? '') . '/' . $data['name'],
                'size'             => $data['size'] ?? 0,
                'storage_type'     => 'onedrive',
                'parent_id'        => $parentId,
                'web_url'          => null,
                'download_url'     => null,
                'is_trashed'       => false,
            ]
        );

        $this->grantPermission($file, $userId, 'owner');
        return $file;
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
}
