<?php

namespace App\Traits;

use App\Models\File;
use App\Models\FilePermission;
use App\Models\FileHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

trait LocalFileManager
{
    /**
     * Permission check helper
     */
    protected function checkPermission($fileId, $action)
    {
        $user = Auth::guard('api')->user();
        $permission = FilePermission::where('file_id', $fileId)
            ->where('user_id', $user->id)
            ->first();

        if (!$permission) return false;

        $map = [
            'view' => ['owner', 'view'],
            'upload' => ['owner', 'upload'],
            'create_folder' => ['owner', 'create_folder'],
            'edit' => ['owner', 'edit'],
            'delete' => ['owner', 'delete'],
        ];

        return in_array($permission->permission, $map[$action] ?? []);
    }

    /**
     * List files & folders
     */
    public function listFilesTrait($path = '/')
    {
        $dbFiles = File::where('path', 'like', $path . '%')->get();

        // Filter DB files by user permission
        $userFiles = $dbFiles->filter(function ($file) {
            return $this->checkPermission($file->id, 'view');
        })->values();

        // Include only storage files that have DB record & permission
        $storageFiles = Storage::disk('public')->files($path);
        $storageFiles = collect($storageFiles)->filter(function ($filePath) use ($userFiles) {
            return $userFiles->contains(fn($f) => $f->path === $filePath);
        })->values();

        $storageFolders = Storage::disk('public')->directories($path);
        $storageFolders = collect($storageFolders)->filter(function ($folderPath) {
            $folder = File::where('path', $folderPath)->where('type', 'folder')->first();
            return $folder && $this->checkPermission($folder->id, 'view');
        })->values();

        return [
            'status' => 'success',
            'files' => $userFiles,
            'storage_files' => $storageFiles,
            'storage_folders' => $storageFolders,
        ];
    }


    /**
     * Upload a file
     */
    public function uploadFileTrait(Request $request, $path = '/')
    {
        // Normalize path
        $path = trim($path, '/');

        // Fetch parent folder from DB
        $parentFolder = File::where('path', $path)->where('type', 'folder')->first();

        if (!$parentFolder) {
            return ['status' => 'error', 'message' => 'Parent folder not found in DB'];
        }

        // Check permission
        if (!$this->checkPermission($parentFolder->id, 'upload')) {
            return ['status' => 'error', 'message' => 'Permission denied for this folder'];
        }

        $request->validate([
            'file' => 'required|file|max:10240', // 10 MB
        ]);

        $uploadedFile = $request->file('file');
        $storedPath = $uploadedFile->store($path, 'public');

        // Create DB entry
        $file = File::create([
            'user_id' => auth()->id(),
            'name' => $uploadedFile->getClientOriginalName(),
            'type' => 'file',
            'path' => $storedPath,
            'size' => $uploadedFile->getSize(),
        ]);

        // Assign owner permission
        FilePermission::create([
            'file_id' => $file->id,
            'user_id' => auth()->id(),
            'permission' => 'owner',
        ]);

        // Log history
        FileHistory::create([
            'file_id' => $file->id,
            'user_id' => auth()->id(),
            'action' => 'upload',
            'metadata' => ['name' => $file->name],
        ]);

        return [
            'status' => 'success',
            'file' => $file,
        ];
    }

    /**
     * Delete a file
     */
    public function deleteFileTrait($filename)
    {
        // Find file by name
        $file = File::where('name', $filename)->first();

        if (!$file) {
            return ['status' => 'error', 'message' => 'File not found'];
        }

        // Permission check
        if (!$this->checkPermission($file->id, 'delete')) {
            return ['status' => 'error', 'message' => 'Permission denied'];
        }

        // Delete from storage
        if (Storage::disk('public')->exists($file->path)) {
            Storage::disk('public')->delete($file->path);
        }

        // Delete from DB
        $file->delete();

        // Log history
        FileHistory::create([
            'file_id' => $file->id,
            'user_id' => auth()->id(),
            'action' => 'delete',
            'metadata' => ['name' => $filename],
        ]);

        return ['status' => 'success', 'message' => 'File deleted'];
    }


    /**
     * Create a folder
     */
    public function createFolderTrait($name, $path = '/')
    {
        // Normalize path
        $path = trim($path, '/');

        // Find parent folder in DB
        $parentFolder = File::where('path', $path)->where('type', 'folder')->first();

        if (!$parentFolder) {
            return ['status' => 'error', 'message' => 'Parent folder not found in DB'];
        }

        // Check permission
        if (!$this->checkPermission($parentFolder->id, 'create_folder')) {
            return ['status' => 'error', 'message' => 'Permission denied'];
        }

        // Create full folder path
        $fullPath = $path ? $path . '/' . $name : $name;

        // Create folder in storage
        Storage::disk('public')->makeDirectory($fullPath);

        // Create folder entry in DB
        $folder = File::create([
            'user_id' => auth()->id(),
            'name' => $name,
            'type' => 'folder',
            'path' => $fullPath,
            'size' => 0,
        ]);

        // Assign owner permission
        FilePermission::create([
            'file_id' => $folder->id,
            'user_id' => auth()->id(),
            'permission' => 'owner',
        ]);

        // Log history
        FileHistory::create([
            'file_id' => $folder->id,
            'user_id' => auth()->id(),
            'action' => 'create_folder',
            'metadata' => ['name' => $name, 'path' => $fullPath],
        ]);

        return [
            'status' => 'success',
            'folder' => $folder,
        ];
    }


    /**
     * Delete a folder
     */
    public function deleteFolderTrait($path)
    {
        // Normalize path
        $path = trim($path, '/');

        $folder = File::where('path', $path)->where('type', 'folder')->first();

        if (!$folder) {
            return ['status' => 'error', 'message' => 'Folder not found'];
        }

        // Permission check
        if (!$this->checkPermission($folder->id, 'delete')) {
            return ['status' => 'error', 'message' => 'Permission denied'];
        }

        // Delete folder from storage
        Storage::disk('public')->deleteDirectory($path);

        // Delete folder from DB
        $folder->delete();

        // Log history
        FileHistory::create([
            'file_id' => $folder->id,
            'user_id' => auth()->id(),
            'action' => 'delete_folder',
            'metadata' => ['path' => $path],
        ]);

        return ['status' => 'success', 'message' => 'Folder deleted'];
    }
}
