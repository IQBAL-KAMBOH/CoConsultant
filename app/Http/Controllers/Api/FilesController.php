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
        $path = $request->get('path', '/');
        return response()->json($this->listFilesTrait($path));
    }

    public function upload(Request $request)
    {
        $path = $request->get('path', '/');
        return response()->json($this->uploadFileTrait($request, $path));
    }

    public function deleteFile($filename)
    {
        return response()->json($this->deleteFileTrait($filename));
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
