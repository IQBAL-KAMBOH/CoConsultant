<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\File;
use App\Models\FilePermission;
use App\Models\User;

class FilePermissionController extends Controller
{
    /**
     * Assign or update permission to a user for a file/folder
     */
    public function assign(Request $request)
    {
        $request->validate([
            'file_id' => 'required|exists:files,id',
            'user_id' => 'required|exists:users,id',
            'permission' => 'required|string|in:owner,view,upload,edit,delete,create_folder',
        ]);

        $filePermission = FilePermission::updateOrCreate(
            [
                'file_id' => $request->file_id,
                'user_id' => $request->user_id,
                'permission' => $request->permission
            ],
            [
                'permission' => $request->permission
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Permission assigned/updated successfully',
            'permission' => $filePermission
        ]);
    }

    /**
     * Remove permission for a user
     */
    public function remove(Request $request)
    {
        $request->validate([
            'file_id' => 'required|exists:files,id',
            'user_id' => 'required|exists:users,id',
            'permission' => 'required|string|in:owner,view,upload,edit,delete,create_folder',
        ]);

        $deleted = FilePermission::where('file_id', $request->file_id)
            ->where('user_id', $request->user_id)
            ->where('permission', $request->permission)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => $deleted ? 'Permission removed' : 'No permission found'
        ]);
    }

    /**
     * List all permissions for a file/folder
     */
    public function list($fileId)
    {
        $file = File::findOrFail($fileId);

        $permissions = FilePermission::where('file_id', $file->id)
            ->with('user:id,name,email')
            ->get();

        return response()->json([
            'status' => 'success',
            'file' => $file,
            'permissions' => $permissions
        ]);
    }

    /**
     * List all permissions for a specific user
     */
    public function listByUser($userId)
    {
        $permissions = FilePermission::where('user_id', $userId)
            ->with('file:id,name,type,path')
            ->get();

        return response()->json([
            'status' => 'success',
            'user_id' => $userId,
            'permissions' => $permissions
        ]);
    }
}
