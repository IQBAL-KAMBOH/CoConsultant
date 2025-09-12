<?php

namespace App\Traits;

use App\Models\File;
use App\Models\FilePermission;
use Illuminate\Support\Facades\Auth;

trait FilePermissionHelper
{
    protected function checkPermission($file, $action)
    {
        $user = Auth::guard('api')->user();
        // $file = File::find($fileId);


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
}
