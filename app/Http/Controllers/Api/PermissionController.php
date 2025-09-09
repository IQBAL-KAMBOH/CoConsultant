<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PermissionController extends Controller
{
    public function __construct()
    {
        $this->middleware('jwt.auth');
        $this->middleware(['jwt.auth', 'permission:permissions.manage']);
    }

    public function index()
    {
        $permissions = Permission::all();

        return response()->json([
            'status' => 'success',
            'data'   => $permissions
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:255|unique:permissions,name',
            'guard_name' => 'nullable|string|in:api,web'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $permission = DB::transaction(function () use ($request) {
                return Permission::create([
                    'name'       => $request->name,
                    'guard_name' => $request->guard_name ?? 'api'
                ]);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Permission created successfully',
                'data'    => $permission
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to create permission',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $permission = Permission::find($id);

        if (!$permission) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Permission not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $permission
        ]);
    }

    public function update(Request $request, $id)
    {
        $permission = Permission::find($id);

        if (!$permission) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Permission not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:255|unique:permissions,name,' . $permission->id,
            'guard_name' => 'nullable|string|in:api,web'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::transaction(function () use ($permission, $request) {
                $permission->update([
                    'name'       => $request->name,
                    'guard_name' => $request->guard_name ?? $permission->guard_name
                ]);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Permission updated successfully',
                'data'    => $permission->fresh()
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update permission',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $permission = Permission::find($id);

        if (!$permission) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Permission not found'
            ], 404);
        }

        try {
            DB::transaction(function () use ($permission) {
                $permission->delete();
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Permission deleted successfully'
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to delete permission',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
