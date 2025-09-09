<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run()
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define API permissions
        $permissions = [
            'users.list',
            'users.view',
            'users.create',
            'users.update',
            'users.delete',

            'files.list',
            'files.upload',
            'files.update',
            'files.delete',

            'roles.manage',
            'permissions.manage',
        ];

        // Create or update permissions
        foreach ($permissions as $perm) {
            Permission::updateOrCreate(
                ['name' => $perm, 'guard_name' => 'api'],
                ['name' => $perm, 'guard_name' => 'api']
            );
        }

        // Roles with permissions
        $roles = [
            'admin' => $permissions, // all permissions
            'manager' => [
                'users.list', 'users.view',
                'files.list', 'files.upload', 'files.update',
            ],
            'user' => [
                'files.list', 'files.upload',
            ],
        ];

        // Create roles and assign permissions
        foreach ($roles as $roleName => $perms) {
            $role = Role::updateOrCreate(
                ['name' => $roleName, 'guard_name' => 'api'],
                ['name' => $roleName, 'guard_name' => 'api']
            );

            $role->syncPermissions($perms); // replaces givePermissionTo with syncing
        }
    }
}
