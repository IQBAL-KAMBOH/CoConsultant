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

    public function ensureRootDirectory()
    {
        $rootName = 'CoConsultRoot';
        $disk = Storage::disk('public'); // ✅ default Laravel public disk
        $rootPath = $rootName;

        // 1. Create physical directory if not exists
        if (!$disk->exists($rootPath)) {
            $disk->makeDirectory($rootPath);
        }

        // 2. Create DB entry if not exists
        $rootFolder = File::firstOrCreate(
            ['path' => $rootPath, 'type' => 'folder'],
            [
                'user_id' => 1, // 👈 assuming admin has ID=1
                'name' => $rootName,
                'size' => 0,
            ]
        );

        // 3. Assign owner permission to admin if not exists
        FilePermission::firstOrCreate([
            'file_id' => $rootFolder->id,
            'user_id' => 1, // admin
            'permission' => 'owner',
        ]);

        return $rootFolder;
    }
    /**
     * Permission check helper
     */
    protected function checkPermission($fileId, $action)
    {
        $user = Auth::guard('api')->user();
        $file = File::find($fileId);

        if (!$file) {
            return false;
        }

        // ✅ Check if user is owner AND has "owner" permission explicitly
        $hasOwnerPermission = FilePermission::where('file_id', $fileId)
            ->where('user_id', $user->id)
            ->where('permission', 'owner')
            ->exists();

        if ($file->user_id === $user->id && $hasOwnerPermission) {
            return true; // full access for owner
        }

        // 🔑 Otherwise, check specific permission
        $permission = FilePermission::where('file_id', $fileId)
            ->where('user_id', $user->id)
            ->where('permission', $action)
            ->first();

        if (!$permission) {
            return false;
        }

        $map = [
            'view'          => ['owner', 'view'],
            'upload'        => ['owner', 'upload'],
            'create_folder' => ['owner', 'create_folder'],
            'edit'          => ['owner', 'edit'],
            'delete'        => ['owner', 'delete'],
        ];

        return in_array($permission->permission, $map[$action] ?? []);
    }



    /**
     * List files & folders
     */
    /**
     * List files & folders by parent_id (default root)
     */
    public function listFilesTrait($parentId = null)
    {
        // If no parent_id is provided, assume root (CoConsultRoot)
        $currentName = '';
        $currentPath = '';
        if (is_null($parentId)) {
            $root = File::where('name', 'CoConsultRoot')->whereNull('parent_id')->first();

            if (!$root) {
                return ['status' => 'error', 'message' => 'Root folder not found'];
            }

            $parentId = $root->id;
            $currentName = $root->name ?? null;
            $currentPath = $root->path ?? null;
        } else {
            $root = File::find($parentId);
            $parentId = $root->id ?? 0;
            $currentName = $root->name ?? null;
            $currentPath = $root->path ?? null;
        }

        // Fetch all children of the given folder
        $dbFiles = File::where('parent_id', $parentId)->get();

        // Filter by user permission
        $userFiles = $dbFiles->filter(function ($file) {
            return $this->checkPermission($file->id, 'view');
        })->values();

        // Split into folders and files
        $folders = $userFiles->where('type', 'folder')->values();
        $files   = $userFiles->where('type', 'file')->values();

        return [
            'status'  => 'success',
            'id'  => $parentId,
            'currentFolder'  => $currentName,
            'currentPath'  => $currentPath,
            'folders' => $folders,
            'files'   => $files,
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
            'parent_id' => $parentFolder->id
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
    public function deleteFileTrait($fileId)
    {
        // Find file by id
        $file = File::find($fileId);

        if (!$file) {
            return ['status' => 'error', 'message' => 'File not found'];
        }

        // Permission check
        if (!$this->checkPermission($file->id, 'delete')) {
            return ['status' => 'error', 'message' => 'Permission denied'];
        }

        // Recursive delete function
        $this->deleteFileAndChildren($file);

        return ['status' => 'success', 'message' => 'File and children deleted'];
    }

    /**
     * Recursive deletion of file/folder and its children
     */
    protected function deleteFileAndChildren(File $file)
    {
        // Delete children first
        $children = File::where('parent_id', $file->id)->get();
        foreach ($children as $child) {
            $this->deleteFileAndChildren($child);
        }

        // Delete from storage (if file)
        if ($file->path && Storage::disk('public')->exists($file->path)) {
            Storage::disk('public')->delete($file->path);
        }

        $filename = $file->name;

        // Delete permissions
        FilePermission::where('file_id', $file->id)->delete();



        // Delete file record
        $file->delete();

        // Log parent delete action
        FileHistory::create([
            'file_id' => $file->id,
            'user_id' => auth()->id(),
            'action' => 'delete',
            'metadata' => ['name' => $filename],
        ]);
    }


    /**
     * Create a folder
     */
    public function createFolderTrait($name, $path = '/')
    {
        $root = $this->ensureRootDirectory();
        $parentFolder = File::where('path', $path)->first();
        if ($parentFolder && !$this->checkPermission($parentFolder->id, 'create_folder')) {
            return ['status' => 'error', 'message' => 'Permission denied'];
        }



        $fullPath = trim($path, '/') . '/' . $name;
        Storage::disk('public')->makeDirectory($fullPath);

        $folder = File::create([
            'user_id' => auth()->id(),
            'name' => $name,
            'type' => 'folder',
            'path' => $fullPath,
            'size' => 0,
            'parent_id' => $parentFolder->id
        ]);

        FilePermission::create([
            'file_id' => $folder->id,
            'user_id' => auth()->id(),
            'permission' => 'owner',
        ]);

        FileHistory::create([
            'file_id' => $folder->id,
            'user_id' => auth()->id(),
            'action' => 'create_folder',
            'metadata' => ['name' => $name],
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
