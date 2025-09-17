<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class OneDriveService
{
    protected $tenantId;
    protected $clientId;
    protected $clientSecret;

    public function __construct()
    {
        $this->tenantId = config('services.microsoft.tenant_id');
        $this->clientId = config('services.microsoft.client_id');
        $this->clientSecret = config('services.microsoft.client_secret');
    }

    public function getAccessToken()
    {
        $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";

        $response = Http::asForm()->post($url, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed()) {
            throw new \Exception("Failed to get token: " . $response->body());
        }

        return $response->json()['access_token'];
    }

    public function createFolder(string $name, ?string $parentOneDriveId = null): array
    {
        try {
            $token = $this->getAccessToken();
            $userPrincipalName = config('services.microsoft.storage_user');

            // 🔍 Only check inside the given parent folder (not recursive)
            $checkUrl = $parentOneDriveId
                ? "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$parentOneDriveId}/children?\$filter=name eq '{$name}'"
                : "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/root/children?\$filter=name eq '{$name}'";

            $checkResponse = Http::withToken($token)->get($checkUrl);

            if ($checkResponse->failed()) {
                throw new \Exception("Failed to check OneDrive folder existence: " . $checkResponse->body());
            }

            $existing = $checkResponse->json()['value'][0] ?? null;
            if ($existing) {
                // ✅ Folder exists → return it
                return $existing;
            }

            // 🚀 Create new folder if not found
            $body = [
                'name' => $name,
                'folder' => new \stdClass(),
                '@microsoft.graph.conflictBehavior' => 'rename',
            ];

            $url = $parentOneDriveId
                ? "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$parentOneDriveId}/children"
                : "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/root/children";

            $response = Http::withToken($token)->post($url, $body);

            if ($response->failed()) {
                throw new \Exception("Failed to create OneDrive folder: " . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            throw new \Exception("Error creating OneDrive folder: " . $e->getMessage());
        }
    }


    public function syncDrive($deltaLink = null)
    {
        $token = $this->getAccessToken();
        $user = config('services.microsoft.storage_user');

        $url = $deltaLink
            ? $deltaLink
            : "https://graph.microsoft.com/v1.0/users/{$user}/drive/root/delta";

        $response = Http::withToken($token)->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to sync drive: " . $response->body());
        }

        return $response->json();
    }

    public function fetchAllItems($parentId = null)
    {
        $token = $this->getAccessToken();
        $user = config('services.microsoft.storage_user');

        $url = $parentId
            ? "https://graph.microsoft.com/v1.0/users/{$user}/drive/items/{$parentId}/children"
            : "https://graph.microsoft.com/v1.0/users/{$user}/drive/root/children";

        $allItems = [];

        do {
            $response = Http::withToken($token)->get($url);

            if ($response->failed()) {
                throw new \Exception("Failed to fetch OneDrive items: " . $response->body());
            }

            $data = $response->json();
            $items = $data['value'] ?? [];

            foreach ($items as $item) {
                $allItems[] = $item;

                if (isset($item['folder'])) {
                    $children = $this->fetchAllItems($item['id']);
                    $allItems = array_merge($allItems, $children);
                }
            }

            $url = $data['@odata.nextLink'] ?? null;
        } while ($url);

        return $allItems;
    }

    public function getDownloadUrl(string $oneDriveFileId): ?string
    {
        try {
            $token = $this->getAccessToken();
            $userPrincipalName = config('services.microsoft.storage_user');

            $url = "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$oneDriveFileId}";

            $response = Http::withToken($token)->get($url);

            if ($response->failed()) {
                throw new Exception("Failed to fetch file metadata: " . $response->body());
            }

            $data = $response->json();

            return $data['@microsoft.graph.downloadUrl'] ?? null;
        } catch (Exception $e) {
            throw new Exception("Error generating download URL: " . $e->getMessage());
        }
    }

    public function rename(string $oneDriveFileId, string $newName): array
    {
        try {
            $token = $this->getAccessToken();
            $userPrincipalName = config('services.microsoft.storage_user');

            $url = "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/items/{$oneDriveFileId}";

            $response = Http::withToken($token)
                ->patch($url, [
                    'name' => $newName
                ]);

            if ($response->failed()) {
                throw new Exception("Failed to rename file: " . $response->body());
            }

            return $response->json();
        } catch (Exception $e) {
            throw new Exception("Error renaming OneDrive file: " . $e->getMessage());
        }
    }

    public function getStorageUsage()
    {
        try {
            $token = $this->getAccessToken();
            $userPrincipalName = config('services.microsoft.storage_user');

            $url = "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive";

            $response = Http::withToken($token)->get($url);

            if ($response->failed()) {
                throw new Exception("Failed to fetch storage usage: " . $response->body());
            }

            $data = $response->json();

            $total = $data['quota']['total'] ?? 0;
            $used = $data['quota']['used'] ?? 0;
            $remaining = $data['quota']['remaining'] ?? 0;
            $deleted = $data['quota']['deleted'] ?? 0;

            $percentage = $total > 0 ? round(($used / $total) * 100, 2) : null;

            return [
                'total_bytes' => $total,
                'used_bytes' => $used,
                'remaining_bytes' => $remaining,
                'deleted_bytes' => $deleted,

                'total' => $this->formatBytes($total),
                'used' => $this->formatBytes($used),
                'remaining' => $this->formatBytes($remaining),
                'deleted' => $this->formatBytes($deleted),

                'state' => $data['quota']['state'] ?? null,
                'percentage_used' => $percentage
            ];
        } catch (Exception $e) {
            throw new Exception("Error fetching storage usage: " . $e->getMessage());
        }
    }

    private function formatBytes($bytes, $precision = 2)
    {
        if ($bytes <= 0) {
            return "0 B";
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        $value = $bytes / (1024 ** $power);

        return round($value, $precision) . ' ' . $units[$power];
    }

    public function getRecentFiles()
    {
        try {
            $token = $this->getAccessToken();
            $userPrincipalName = config('services.microsoft.storage_user');

            $url = "https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive/recent";

            $response = Http::withToken($token)->get($url);

            if ($response->failed()) {
                throw new Exception("Failed to fetch recent files: " . $response->body());
            }

            return $response->json()['value'] ?? [];
        } catch (Exception $e) {
            throw new Exception("Error fetching recent files: " . $e->getMessage());
        }
    }

    /** 🔹 NEW FUNCTIONS (no old code touched) */

    public function delete(string $oneDriveFileId): bool
    {
        $token = $this->getAccessToken();
        $user = config('services.microsoft.storage_user');

        $url = "https://graph.microsoft.com/v1.0/users/{$user}/drive/items/{$oneDriveFileId}";

        $response = Http::withToken($token)->delete($url);

        if ($response->failed()) {
            throw new Exception("Failed to delete item: " . $response->body());
        }

        return true;
    }

    public function move(string $oneDriveFileId, string $newParentId): array
    {
        $token = $this->getAccessToken();
        $user = config('services.microsoft.storage_user');

        $url = "https://graph.microsoft.com/v1.0/users/{$user}/drive/items/{$oneDriveFileId}";

        $response = Http::withToken($token)->patch($url, [
            'parentReference' => [
                'id' => $newParentId
            ]
        ]);

        if ($response->failed()) {
            throw new Exception("Failed to move item: " . $response->body());
        }

        return $response->json();
    }
    public function uploadFile($uploadedFile, ?string $parentOneDriveId = null): array
    {
        $userPrincipalName = config('services.microsoft.storage_user');

        $path = $parentOneDriveId
            ? "/items/{$parentOneDriveId}:/{$uploadedFile->getClientOriginalName()}:/content"
            : "/root:/{$uploadedFile->getClientOriginalName()}:/content";

        $response = Http::withToken($this->getAccessToken())
            ->withBody(
                file_get_contents($uploadedFile->getRealPath()),
                $uploadedFile->getMimeType() // keep your original
            )
            ->put("https://graph.microsoft.com/v1.0/users/{$userPrincipalName}/drive{$path}");

        if ($response->failed()) {
            throw new \Exception("Failed to upload file: " . $response->body());
        }

        return $response->json();
    }


    public function copy(string $oneDriveFileId, string $newParentId, string $newName): array
    {
        $token = $this->getAccessToken();
        $user = config('services.microsoft.storage_user');

        $url = "https://graph.microsoft.com/v1.0/users/{$user}/drive/items/{$oneDriveFileId}/copy";

        $response = Http::withToken($token)->post($url, [
            'parentReference' => ['id' => $newParentId],
            'name' => $newName
        ]);

        if ($response->failed()) {
            throw new Exception("Failed to copy item: " . $response->body());
        }

        return $response->json();
    }

    public function createShareLink(string $oneDriveFileId, string $type = 'view'): array
    {
        $token = $this->getAccessToken();
        $user = config('services.microsoft.storage_user');

        $url = "https://graph.microsoft.com/v1.0/users/{$user}/drive/items/{$oneDriveFileId}/createLink";

        $response = Http::withToken($token)->post($url, [
            'type' => $type, // view | edit | embed
            'scope' => 'anonymous'
        ]);

        if ($response->failed()) {
            throw new Exception("Failed to create share link: " . $response->body());
        }

        return $response->json();
    }

    public function preview(string $oneDriveFileId): array
    {
        $token = $this->getAccessToken();
        $user = config('services.microsoft.storage_user');

        $url = "https://graph.microsoft.com/v1.0/users/{$user}/drive/items/{$oneDriveFileId}/preview";

        $response = Http::withToken($token)->post($url, []);

        if ($response->failed()) {
            throw new Exception("Failed to generate preview: " . $response->body());
        }

        return $response->json();
    }
}
