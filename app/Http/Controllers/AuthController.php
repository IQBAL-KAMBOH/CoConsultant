<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');

        $token = Auth::guard('api')->attempt($credentials);

        if (! $token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }

        $user = Auth::guard('api')->user();

        return response()->json([
            'status' => 'success',
            'user' => [
                'id'            => $user->id,
                'name'          => $user->name,
                'last_name'     => $user->last_name,
                'email'         => $user->email,
                'phone_number'  => $user->phone_number,
                'profile_photo' => $user->profile_photo ? asset('storage/' . $user->profile_photo) : null,
                'roles'         => $user->getRoleNames(),
                'permissions'   => $user->getAllPermissions()->pluck('name'),
            ],
            'authorisation' => [
                'token' => $token,
                'type'  => 'bearer',
            ]
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name'          => 'required|string|max:255',
            'last_name'     => 'nullable|string|max:255',
            'email'         => 'required|string|email|max:255|unique:users',
            'password'      => 'required|string|min:6',
            'phone_number'  => 'nullable|string|max:20',
            'profile_photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        $profilePhotoPath = null;
        if ($request->hasFile('profile_photo')) {
            $profilePhotoPath = $request->file('profile_photo')->store('profile_photos', 'public');
        }

        $user = User::create([
            'name'          => $request->name,
            'last_name'     => $request->last_name,
            'email'         => $request->email,
            'password'      => Hash::make($request->password),
            'phone_number'  => $request->phone_number,
            'profile_photo' => $profilePhotoPath,
        ]);

        $user->assignRole('user');

        $token = Auth::guard('api')->login($user);

        return response()->json([
            'status'  => 'success',
            'message' => 'User created successfully',
            'user'    => [
                'id'            => $user->id,
                'name'          => $user->name,
                'last_name'     => $user->last_name,
                'email'         => $user->email,
                'phone_number'  => $user->phone_number,
                'profile_photo' => $user->profile_photo ? asset('storage/' . $user->profile_photo) : null,
                'roles'         => $user->getRoleNames(),
                'permissions'   => $user->getAllPermissions()->pluck('name'),
            ],
            'authorisation' => [
                'token' => $token,
                'type'  => 'bearer',
            ]
        ]);
    }


    public function logout()
    {
        Auth::guard('api')->logout();

        return response()->json([
            'status'  => 'success',
            'message' => 'Successfully logged out',
        ]);
    }

    public function refresh()
    {
        $user = Auth::guard('api')->user();

        return response()->json([
            'status' => 'success',
            'user' => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'roles'       => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'authorisation' => [
                'token' => Auth::guard('api')->refresh(),
                'type'  => 'bearer',
            ]
        ]);
    }
    public function updateProfile(Request $request)
    {
        $user = Auth::guard('api')->user();

        $request->validate([
            'name'          => 'sometimes|string|max:255',
            'last_name'     => 'nullable',
            'email'         => 'sometimes|email|unique:users,email,' . $user->id,
            'phone_number'  => 'nullable',
            'profile_photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->has('name')) $user->name = $request->name;
        if ($request->has('last_name')) $user->last_name = $request->last_name;
        if ($request->has('email')) $user->email = $request->email;
        if ($request->has('phone_number')) $user->phone_number = $request->phone_number;

        if ($request->hasFile('profile_photo')) {
            // delete old photo if exists
            if ($user->profile_photo && Storage::disk('public')->exists($user->profile_photo)) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            $user->profile_photo = $request->file('profile_photo')->store('profile_photos', 'public');
        }

        $user->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Profile updated successfully',
            'user'    => [
                'id'            => $user->id,
                'name'          => $user->name,
                'last_name'     => $user->last_name,
                'email'         => $user->email,
                'phone_number'  => $user->phone_number,
                'profile_photo' => $user->profile_photo ? asset('storage/' . $user->profile_photo) : null,
                'roles'         => $user->getRoleNames(),
                'permissions'   => $user->getAllPermissions()->pluck('name'),
            ]
        ]);
    }
    public function updatePassword(Request $request)
    {
        $user = Auth::guard('api')->user();

        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Current password is incorrect',
            ], 400);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Password updated successfully',
        ]);
    }
    /**
     * List all starred files for the authenticated user
     */
    public function starredFiles()
    {
        $files = Auth::guard('api')->user()->starredFiles()->get();

        return response()->json([
            'status' => 'ok',
            'data'   => $files
        ]);
    }

    /**
     * Toggle star/unstar for a file (per user)
     */
    public function toggleStar($id)
    {
        $user = Auth::guard('api')->user();
        $file = File::find($id);
        if (!$file) {
            return response()->json([
                'status'  => 'error',
                'message' => 'File Not Found',
            ]);
        }

        if ($user->starredFiles()->where('file_id', $file->id)->exists()) {
            // Unstar
            $user->starredFiles()->detach($file->id);
            $message = 'File unstarred';
        } else {
            // Star
            $user->starredFiles()->attach($file->id);
            $message = 'File starred';
        }

        return response()->json([
            'status'  => 'ok',
            'message' => $message,
        ]);
    }
}
