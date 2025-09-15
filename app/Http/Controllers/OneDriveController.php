<?php

namespace App\Http\Controllers;

use App\Traits\OneDriveFileManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OneDriveController extends Controller
{
    use OneDriveFileManager;

    /** List root or child items */
    public function root(Request $request)
    {
        $items = $this->listOneDriveFiles(
            $request->parent_id ?? null,
            $request->user_id ?? null
        );

        return response()->json([
            'status' => 'ok',
            'data'   => $items,
        ]);
    }


    /** Create a new folder */
    public function createFolder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'      => 'required|string|max:255',
            'parent_id' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $folder = $this->createOneDriveFolder($request->name, $request->parent_id);

        return response()->json([
            'status' => 'ok',
            'folder' => $folder
        ]);
    }

    /** Upload a file */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file'      => 'required|file',
            'parent_id' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $file = $this->uploadFileToOneDrive(
            $request->parent_id,
            $request->file('file')
        );

        return response()->json([
            'status' => 'ok',
            'file'   => $file
        ]);
    }

    /** Delete a file or folder */
    public function deleteItem($id)
    {
        $result = $this->deleteOneDriveItem($id);

        return response()->json($result);
    }
    public function sync()
    {

        $result = $this->syncOneDrive();

        return response()->json($result);
    }
    public function move(Request $request, string $fileId)
    {
        $request->validate([
            'new_parent_id' => 'required|string',
            'new_name'      => 'nullable|string|max:255',
        ]);

        $newparentId = $request->new_parent_id ?? null;

        return $this->moveOneDrive($fileId, $newparentId);
    }
    public function getFileDownloadUrl($id)
    {
        return $this->handleFileDownloadUrl($id);
    }
    public function trash($id)
    {
        $result = $this->trashFile($id);
        return response()->json($result);
    }
    public function bulkTrash(Request $request)
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'integer|exists:files,id'
        ]);

        $result = $this->trashBulkFiles($request->file_ids);

        return response()->json($result);
    }

    public function restore($id)
    {
        $result = $this->restoreFile($id);
        return response()->json($result);
    }
    public function bulkRestore(Request $request)
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'integer|exists:files,id'
        ]);
        $result = $this->bulkRestoreFiles($request->file_ids);

        return response()->json($result);
    }

    public function trashed(Request $request)
    {
        $items = $this->listTrashedFiles($request->user_id ?? null);
        return response()->json([
            'status' => 'ok',
            'data'   => $items
        ]);
    }

}
