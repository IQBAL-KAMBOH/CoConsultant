<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
        $this->middleware('permission:permission.view')->only(['index', 'show']);
        $this->middleware('permission:permission.create')->only(['store']);
        $this->middleware('permission:permission.update')->only(['update']);
        $this->middleware('permission:permission.delete')->only(['destroy']);
    }

    public function index()
    {
        return response()->json(Permission::all());
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|unique:permissions']);
        $permission = Permission::create(['name' => $request->name]);

        return response()->json($permission);
    }

    public function show($id)
    {
        return response()->json(Permission::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);
        $permission->update(['name' => $request->name]);

        return response()->json($permission);
    }

    public function destroy($id)
    {
        Permission::findOrFail($id)->delete();
        return response()->json(['message' => 'Permission deleted successfully']);
    }
}
