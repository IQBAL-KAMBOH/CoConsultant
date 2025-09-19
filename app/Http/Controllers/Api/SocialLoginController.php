<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class SocialLoginController extends Controller
{
    /** Google Redirect */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /** Google Callback */
    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $user = User::where('email', $googleUser->getEmail())->first();

        if (!$user) {
            // create new user if doesn't exist
            $user = User::create([
                'name'      => $googleUser->getName(),
                'email'     => $googleUser->getEmail(),
                'password'  => Hash::make(uniqid('google_', true)), // random password
                'google_id' => $googleUser->getId(),
            ]);
            $user->assignRole('user'); // 👈 same as register
        }

        $token = Auth::guard('api')->login($user);

        return response()->json([
            'status' => 'success',
            'user'   => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'roles'         => $user->getRoleNames(),
                'permissions'   => $user->getAllPermissions()->pluck('name'),
            ],
            'authorisation' => [
                'token' => $token,
                'type'  => 'bearer',
            ]
        ]);
    }

    /** Facebook Redirect */
    public function redirectToFacebook()
    {
        return Socialite::driver('facebook')->stateless()->redirect();
    }

    /** Facebook Callback */
    public function handleFacebookCallback()
    {
        $fbUser = Socialite::driver('facebook')->stateless()->user();

        $user = User::where('email', $fbUser->getEmail())->first();

        if (!$user) {
            $user = User::create([
                'name'        => $fbUser->getName(),
                'email'       => $fbUser->getEmail(),
                'password'    => Hash::make(uniqid('facebook_', true)),
                'facebook_id' => $fbUser->getId(),
            ]);
            $user->assignRole('user');
        }

        $token = Auth::guard('api')->login($user);

        return response()->json([
            'status' => 'success',
            'user'   => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'roles'       => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'authorisation' => [
                'token' => $token,
                'type'  => 'bearer',
            ]
        ]);
    }

    /** GitHub Redirect */
    public function redirectToGithub()
    {
        return Socialite::driver('github')->stateless()->redirect();
    }

    /** GitHub Callback */
    public function handleGithubCallback()
    {
        $githubUser = Socialite::driver('github')->stateless()->user();

        $user = User::where('email', $githubUser->getEmail())->first();

        if (!$user) {
            $user = User::create([
                'name'      => $githubUser->getName(),
                'email'     => $githubUser->getEmail(),
                'password'  => Hash::make(uniqid('github_', true)),
                'github_id' => $githubUser->getId(),
            ]);
            $user->assignRole('user');
        }

        $token = Auth::guard('api')->login($user);

        return response()->json([
            'status' => 'success',
            'user'   => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'roles'       => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'authorisation' => [
                'token' => $token,
                'type'  => 'bearer',
            ]
        ]);
    }
}
