<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function __construct()
    {
        $this->middleware('jwt.auth');
        $this->middleware('permission:roles.manage')->only(['index', 'show', 'store', 'update', 'destroy']);
    }

    /**
     * List all roles with permissions
     */
    public function index()
    {
        $roles = Role::with('permissions')->get();

        return response()->json([
            'status' => 'success',
            'data'   => $roles
        ], 200);
    }

    /**
     * Create a new role
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name',
            'permissions' => 'array',
        ], [
            'name.required' => 'Role name is required.',
            'name.unique' => 'This role name already exists.',
            'permissions.array' => 'Permissions must be sent as an array.',
        ]);

        $validator->after(function ($validator) use ($request) {
            if ($request->has('permissions')) {
                $invalid = collect($request->permissions)
                    ->diff(Permission::pluck('name')); // find non-existing permissions

                if ($invalid->isNotEmpty()) {
                    foreach ($invalid as $perm) {
                        $validator->errors()->add('permissions', "Invalid permission: {$perm}");
                    }
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors(),
            ], 422);
        }
        $validated = $validator->validated();
        try {
            DB::beginTransaction();

            $role = Role::create(['name' => $validated['name']]);

            if (!empty($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Role created successfully',
                'data'    => $role->load('permissions')
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to create role',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show role details
     */
    public function show($id)
    {
        $role = Role::with('permissions')->find($id);

        if (!$role) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Role not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $role
        ], 200);
    }

    /**
     * Update role and its permissions
     */
    public function update(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Role not found'
            ], 404);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255|unique:roles,name,' . $id,
            'permissions' => 'array',
        ], [
            'name.required'     => 'Role name is required.',
            'name.unique'       => 'This role name already exists.',
            'permissions.array' => 'Permissions must be sent as an array.',
        ]);

        // Custom validation for invalid permission names
        $validator->after(function ($validator) use ($request) {
            if ($request->has('permissions')) {
                $validNames = Permission::pluck('name')->toArray();
                $invalid    = collect($request->permissions)->diff($validNames);

                if ($invalid->isNotEmpty()) {
                    foreach ($invalid as $name) {
                        $validator->errors()->add(
                            'permissions',
                            "Invalid permission name: {$name}"
                        );
                    }
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        try {
            DB::beginTransaction();

            // Update role name
            $role->update(['name' => $validated['name']]);

            // Sync permissions if provided
            if (isset($validated['permissions'])) {
                $permissions = Permission::whereIn('name', $validated['permissions'])->get();
                $role->syncPermissions($permissions);
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Role updated successfully',
                'data'    => $role->load('permissions')
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update role',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Delete a role
     */
    public function destroy($id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Role not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            $role->delete();

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Role deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to delete role',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
