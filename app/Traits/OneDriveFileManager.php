<?php

namespace App\Traits;

use App\Models\File;
use App\Models\FilePermission;
use App\Models\FileHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

trait OneDriveFileManager
{
    protected function getAccessToken()
    {
        $tenantId = config('services.microsoft.tenant_id');
        $clientId = config('services.microsoft.client_id');
        $clientSecret = config('services.microsoft.client_secret');

        $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
        ]);

        if ($response->failed()) {
            throw new \Exception("Failed to get access token: " . $response->body());
        }

        return $response->json()['access_token'];
    }

    /** List files in a OneDrive folder */
    public function listOneDriveFiles($parentId = null)
    {
        $token = $this->getAccessToken();
        $userPrincipalName = config('services.microsoft.storage_user');

        $url = $parentId
            ? "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$parentId}/children"
            : "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/root/children";

        $response = Http::withToken($token)->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to list OneDrive files: " . $response->body());
        }

        $items = $response->json()['value'] ?? [];

        // Map items into your File model format for DB
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'type' => isset($item['folder']) ? 'folder' : 'file',
                'size' => $item['size'] ?? 0,
                'webUrl' => $item['webUrl'] ?? null,
            ];
        }

        return $mapped;
    }

    /** Create a folder in OneDrive & record in DB */
    public function createOneDriveFolder($name, $parentId = null)
    {
        $token = $this->getAccessToken();
        $userPrincipalName = config('services.microsoft.storage_user');

        $body = [
            'name' => $name,
            'folder' => new \stdClass(),
            '@microsoft.graph.conflictBehavior' => 'rename',
        ];

        $url = $parentId
            ? "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$parentId}/children"
            : "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/root/children";

        $response = Http::withToken($token)->post($url, $body);

        if ($response->failed()) {
            throw new \Exception("Failed to create folder: " . $response->body());
        }

        $data = $response->json();

        // Record in your DB
        $file = File::create([
            'user_id' => Auth::id(),
            'name' => $data['name'],
            'type' => 'folder',
            'path' => $data['id'], // store OneDrive item ID as path
            'size' => 0,
        ]);

        FilePermission::create([
            'file_id' => $file->id,
            'user_id' => Auth::id(),
            'permission' => 'owner',
        ]);

        FileHistory::create([
            'file_id' => $file->id,
            'user_id' => Auth::id(),
            'action' => 'create_folder',
            'metadata' => ['onedrive_id' => $data['id']],
        ]);

        return $file;
    }

    /** Upload a file to OneDrive and record in DB */
    public function uploadFileToOneDrive($folderId, $uploadedFile)
    {
        $token = $this->getAccessToken();
        $userPrincipalName = config('services.microsoft.storage_user');

        // Determine OneDrive path
        $path = $folderId ? "/{$folderId}/{$uploadedFile->getClientOriginalName()}:/content" : "/root:/{$uploadedFile->getClientOriginalName()}:/content";

        $response = Http::withToken($token)
            ->put("https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive{$path}", file_get_contents($uploadedFile->getRealPath()));

        if ($response->failed()) {
            throw new \Exception("Failed to upload file: " . $response->body());
        }

        $data = $response->json();

        // Record in your DB
        $file = File::create([
            'user_id' => Auth::id(),
            'name' => $uploadedFile->getClientOriginalName(),
            'type' => 'file',
            'path' => $data['id'], // OneDrive item ID
            'size' => $uploadedFile->getSize(),
            'parent_id' => $folderId ? File::where('path', $folderId)->first()->id : null,
        ]);

        FilePermission::create([
            'file_id' => $file->id,
            'user_id' => Auth::id(),
            'permission' => 'owner',
        ]);

        FileHistory::create([
            'file_id' => $file->id,
            'user_id' => Auth::id(),
            'action' => 'upload',
            'metadata' => ['onedrive_id' => $data['id']],
        ]);

        return $file;
    }

    /** Delete a OneDrive file/folder and DB record */
    public function deleteOneDriveItem($fileId)
    {
        $file = File::find($fileId);
        if (!$file) return ['status' => 'error', 'message' => 'File not found'];

        $token = $this->getAccessToken();
        $userPrincipalName = config('services.microsoft.storage_user');

        $response = Http::withToken($token)->delete("https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$file->path}");

        if ($response->failed()) {
            throw new \Exception("Failed to delete OneDrive item: " . $response->body());
        }

        // Delete DB records & permissions recursively if folder
        $this->deleteFileAndChildren($file); // reuse your LocalFileManager delete logic

        return ['status' => 'success'];
    }

    /** Reuse your recursive delete function */
    protected function deleteFileAndChildren($file)
    {
        $children = File::where('parent_id', $file->id)->get();
        foreach ($children as $child) {
            $this->deleteFileAndChildren($child);
        }

        FilePermission::where('file_id', $file->id)->delete();
        $file->delete();

        FileHistory::create([
            'file_id' => $file->id,
            'user_id' => Auth::id(),
            'action' => 'delete',
            'metadata' => ['name' => $file->name],
        ]);
    }
}
