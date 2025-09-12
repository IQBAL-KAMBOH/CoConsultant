<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

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
}
