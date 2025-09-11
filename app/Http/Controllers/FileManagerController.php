<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\LocalFileManager;
use App\Traits\OneDriveFileManager;
use App\Models\File; // To fetch file details for specific operations
use Illuminate\Support\Facades\Session; // For OneDrive authentication

class FileManagerController extends Controller
{
    use LocalFileManager, OneDriveFileManager {
        // Alias ensureRootDirectory and listFilesTrait if names conflict or you want to differentiate
        LocalFileManager::ensureRootDirectory insteadof OneDriveFileManager;
        OneDriveFileManager::ensureRootDirectoryOneDrive as ensureOneDriveRootDirectory;
        // You might need to alias checkPermission if you want different implementations per trait
        // For now, assume a single checkPermission for both.
    }

    public function __construct()
    {
        $this->middleware('auth:api'); // Protect your API routes
    }

    /**
     * Helper to determine which manager to use.
     * Could be based on a route parameter, user preference, or specific file's storage_type.
     */
    protected function getManager(string $storageType)
    {
        if ($storageType === 'local') {
            return $this; // Traits apply to the current object
        } elseif ($storageType === 'onedrive') {
            // Need to ensure OneDrive token is available for OneDrive operations
            if (!Session::has('onedrive_token')) {
                // You might throw an exception or return a specific error
                throw new \Exception('OneDrive not authenticated.');
            }
            return $this;
        }
        throw new \InvalidArgumentException('Invalid storage type specified.');
    }

    /**
     * Initiate OneDrive OAuth flow (User must grant permission)
     */
    public function authenticateOneDrive()
    {
        // This should be your actual OAuth setup.
        // You would typically redirect to Microsoft's login endpoint.
        // For example:
        // $redirectUri = config('services.onedrive.redirect');
        // $clientId = config('services.onedrive.client_id');
        // $scopes = implode(' ', config('services.onedrive.scopes'));
        // $authUrl = "https://login.microsoftonline.com/common/oauth2/v2.0/authorize?" .
        //             "client_id={$clientId}&response_type=code&redirect_uri={$redirectUri}&" .
        //             "response_mode=query&scope={$scopes}";
        // return redirect($authUrl);

        return response()->json(['message' => 'Redirect to Microsoft OAuth for OneDrive authentication.']);
    }

    /**
     * Handle OneDrive OAuth callback and store token
     */
    public function handleOneDriveCallback(Request $request)
    {
        $code = $request->input('code');
        if (!$code) {
            return response()->json(['error' => 'Authorization code not received.'], 400);
        }

        try {
            // Use Guzzle to exchange the code for tokens
            $client = new \GuzzleHttp\Client();
            $tokenUrl = "https://login.microsoftonline.com/common/oauth2/v2.0/token";
            $response = $client->post($tokenUrl, [
                'form_params' => [
                    'client_id' => config('services.onedrive.client_id'),
                    'scope' => implode(' ', config('services.onedrive.scopes')),
                    'code' => $code,
                    'redirect_uri' => config('services.onedrive.redirect'),
                    'grant_type' => 'authorization_code',
                    'client_secret' => config('services.onedrive.client_secret'),
                ]
            ]);

            $tokens = json_decode((string) $response->getBody(), true);
            Session::put('onedrive_token', $tokens['access_token']);
            Session::put('onedrive_refresh_token', $tokens['refresh_token']); // Store refresh token

            // Set token expiration
            Session::put('onedrive_token_expires_at', now()->addSeconds($tokens['expires_in']));

            return response()->json(['message' => 'OneDrive authenticated successfully!', 'token' => $tokens['access_token']]);

        } catch (\Exception $e) {
            \Log::error("OneDrive Token Exchange Error: " . $e->getMessage());
            return response()->json(['error' => 'Failed to authenticate with OneDrive: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Main method to list files, optionaly by storage type
     * @param string $storageType 'local' or 'onedrive' (default: 'local' or user preference)
     * @param int|null $parentId
     */
    public function listFiles(Request $request, string $storageType = 'local', int $parentId = null)
    {
        try {
            $manager = $this->getManager($storageType);
            if ($storageType === 'local') {
                return response()->json($manager->listFilesTrait($parentId));
            } else { // onedrive
                return response()->json($manager->listFilesOneDrive($parentId));
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Upload a file to a specific storage type and parent folder.
     * @param string $storageType
     * @param int $parentId
     * @param Request $request
     */
    public function uploadFile(Request $request, string $storageType, int $parentId)
    {
        try {
            $manager = $this->getManager($storageType);
            if ($storageType === 'local') {
                // For local, uploadFileTrait expects parent path, not parent ID directly
                // You might need to fetch the path from the parentId for local
                $parentFolder = File::find($parentId);
                if (!$parentFolder || $parentFolder->storage_type !== 'local') {
                    return response()->json(['status' => 'error', 'message' => 'Invalid local parent folder.'], 400);
                }
                return response()->json($manager->uploadFileTrait($request, $parentFolder->path));
            } else { // onedrive
                return response()->json($manager->uploadFileOneDrive($request, $parentId));
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a file or folder.
     * @param string $storageType
     * @param int $fileId
     */
    public function deleteFile(string $storageType, int $fileId)
    {
        try {
            $manager = $this->getManager($storageType);
            if ($storageType === 'local') {
                return response()->json($manager->deleteFileTrait($fileId));
            } else { // onedrive
                return response()->json($manager->deleteFileOneDrive($fileId));
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a new folder.
     * @param string $storageType
     * @param int $parentId
     * @param Request $request expects 'name'
     */
    public function createFolder(Request $request, string $storageType, int $parentId)
    {
        $request->validate(['name' => 'required|string|max:255']);
        try {
            $manager = $this->getManager($storageType);
            $folderName = $request->input('name');

            if ($storageType === 'local') {
                $parentFolder = File::find($parentId);
                if (!$parentFolder || $parentFolder->storage_type !== 'local') {
                    return response()->json(['status' => 'error', 'message' => 'Invalid local parent folder.'], 400);
                }
                return response()->json($manager->createFolderTrait($folderName, $parentFolder->path));
            } else { // onedrive
                return response()->json($manager->createFolderOneDrive($folderName, $parentId));
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Download a file.
     * @param string $storageType
     * @param int $fileId
     */
    public function downloadFile(string $storageType, int $fileId)
    {
        try {
            $manager = $this->getManager($storageType);
            if ($storageType === 'local') {
                $file = File::find($fileId);
                if (!$file || $file->storage_type !== 'local') {
                    return response()->json(['status' => 'error', 'message' => 'Invalid local file.'], 400);
                }
                // For local files, you'd stream directly or return a storage URL
                return response()->download(Storage::disk('public')->path($file->path), $file->name);
            } else { // onedrive
                $response = $manager->downloadFileOneDrive($fileId);
                if ($response['status'] === 'success') {
                    return response()->json($response); // Frontend can redirect to download_url
                }
                return response()->json($response, 500);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // You might also need a method to ensure root directories are set up for a user
    public function setupUserStorage(string $storageType)
    {
        try {
            if ($storageType === 'local') {
                $this->ensureRootDirectory();
            } elseif ($storageType === 'onedrive') {
                $this->ensureOneDriveRootDirectory();
            }
            return response()->json(['status' => 'success', 'message' => "{$storageType} root directory ensured."]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Helper method to refresh OneDrive token if expired.
     * This could be called automatically by getGraph() before making a request.
     */
    protected function refreshOneDriveToken()
    {
        if (!Session::has('onedrive_refresh_token') || !Session::has('onedrive_token_expires_at')) {
            return false; // No refresh token or expiration info
        }

        if (Session::get('onedrive_token_expires_at')->isFuture()) {
            return true; // Token is still valid
        }

        // Token expired, attempt to refresh
        try {
            $client = new \GuzzleHttp\Client();
            $tokenUrl = "https://login.microsoftonline.com/common/oauth2/v2.0/token";
            $response = $client->post($tokenUrl, [
                'form_params' => [
                    'client_id' => config('services.onedrive.client_id'),
                    'scope' => implode(' ', config('services.onedrive.scopes')),
                    'refresh_token' => Session::get('onedrive_refresh_token'),
                    'grant_type' => 'refresh_token',
                    'client_secret' => config('services.onedrive.client_secret'),
                ]
            ]);

            $tokens = json_decode((string) $response->getBody(), true);
            Session::put('onedrive_token', $tokens['access_token']);
            Session::put('onedrive_token_expires_at', now()->addSeconds($tokens['expires_in']));
            // If a new refresh_token is provided, update it:
            if (isset($tokens['refresh_token'])) {
                Session::put('onedrive_refresh_token', $tokens['refresh_token']);
            }
            return true;
        } catch (\Exception $e) {
            \Log::error("OneDrive Token Refresh Error: " . $e->getMessage());
            Session::forget(['onedrive_token', 'onedrive_refresh_token', 'onedrive_token_expires_at']); // Clear invalid tokens
            return false;
        }
    }

    // Override the getGraph method in OneDriveFileManager to include refresh logic
    // This allows the unified controller to call refreshOneDriveToken
    public function getGraphOverride() // Renamed to avoid trait method collision if both traits define getGraph()
    {
        if (!$this->refreshOneDriveToken()) {
            throw new Exception("OneDrive token is expired and could not be refreshed. Please re-authenticate.");
        }
        return $this->getGraph(); // Call the original getGraph from the trait
    }
}