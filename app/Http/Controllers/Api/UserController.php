<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('jwt.auth'); // ✅ enforce JWT api guard
        $this->middleware('permission:users.list')->only(['index']);
        $this->middleware('permission:users.view')->only(['show']);
        $this->middleware('permission:users.create')->only(['store']);
        $this->middleware('permission:users.update')->only(['update']);
        $this->middleware('permission:users.delete')->only(['destroy']);
    }

    public function index()
    {
        $data = User::with('roles')
            ->whereDoesntHave('roles', function ($query) {
                $query->whereIn('name', ['admin', 'supp']);
            })
            ->get();

        return response()->json([
            'data' => $data,
        ], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string',
            'email'    => 'required|email|unique:users',
            'password' => 'required|min:6',
            'role'     => 'required'
        ]);

        try {
            $user = DB::transaction(function () use ($request) {
                $user = User::create([
                    'name'      => $request->name,
                    'email'     => $request->email,
                    'password'  => Hash::make($request->password),
                    'user_type' => $request->role,
                ]);

                if ($request->role) {
                    $user->assignRole($request->role);
                }

                if ($request->permissions) {
                    $user->givePermissionTo($request->permissions);
                }

                return $user->load('roles');
            });

            return response()->json($user, 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create user',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = User::with('roles')->find($id);

        if ($data) {
            return response()->json([
                'data' => $data,
            ], 200);
        }

        return response()->json([
            'message' => 'User not found',
            'data' => collect(),
        ], 404);
    }

    public function update(Request $request, $id)
    {
        try {
            $user = DB::transaction(function () use ($request, $id) {
                $user = User::findOrFail($id);

                $user->update($request->only(['name', 'email']));

                if ($request->filled('password')) {
                    $user->update(['password' => Hash::make($request->password)]);
                }

                if ($request->role) {
                    $user->syncRoles($request->role);
                }

                if ($request->permissions) {
                    $user->syncPermissions($request->permissions);
                }

                return $user->load('roles');
            });

            return response()->json($user, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update user',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            DB::transaction(function () use ($id) {
                User::findOrFail($id)->delete();
            });

            return response()->json(['message' => 'User deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete user',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
