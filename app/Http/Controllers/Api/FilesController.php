<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\LocalFileManager;

class FilesController extends Controller
{
    use LocalFileManager;

    public function listFiles(Request $request)
    {
        $fileId = $request->get('file_id', null);
        return response()->json($this->listFilesTrait($fileId));
    }

    public function upload(Request $request)
    {
        $path = $request->get('path', '/');
        return response()->json($this->uploadFileTrait($request, $path));
    }

    public function deleteFile($id)
    {
        return response()->json($this->deleteFileTrait($id));
    }

    public function createFolder(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $path = $request->get('path', '/');
        return response()->json($this->createFolderTrait($request->name, $path));
    }

    public function deleteFolder(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        return response()->json($this->deleteFolderTrait($request->path));
    }
}
