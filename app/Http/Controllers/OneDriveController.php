<?php
namespace App\Http\Controllers;

use App\Traits\OneDriveFileManager;
use Illuminate\Http\Request;

class OneDriveController extends Controller
{
    use OneDriveFileManager;

    public function root()
    {
        $items = $this->listOneDriveFiles();
        return response()->json(['status' => 'ok', 'data' => $items]);
    }

    public function createFolder(Request $request)
    {
        $folder = $this->createOneDriveFolder($request->name, $request->parent_id ?? null);
        return response()->json(['status' => 'ok', 'folder' => $folder]);
    }

    public function upload(Request $request)
    {
        $file = $this->uploadFileToOneDrive($request->folder_id ?? null, $request->file('file'));
        return response()->json(['status' => 'ok', 'file' => $file]);
    }

    public function delete($id)
    {
        $result = $this->deleteOneDriveItem($id);
        return response()->json($result);
    }
}
