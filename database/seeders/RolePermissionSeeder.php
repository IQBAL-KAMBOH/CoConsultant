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

        // Define API permissions for user management
        $userPermissions = [
            'users.list',
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
        ];

        // Define API permissions for file management
        $filePermissions = [
            'files.list',
            'files.create',
            'files.upload',
            'files.update',
            'files.delete',
            'files.trash',
            'files.trashed',
            'files.sync',
            'files.storage-usage',
            'files.restore',
            'files.bulk-restore',
            'files.bulkDelete',
            'files.bulkTrash',
            'files.move',
            'files.rename',
            'files.recent',
            'files.download',
            'file-permissions.assign',
            'file-permissions.remove',
            'file-permissions.list',
            'file-permissions.user-list',
            'starred-files.list',
            'starred-files.toggle',
        ];

        // Define permissions for roles and permissions management
        $systemPermissions = [
            'roles.manage',
            'permissions.manage',
        ];

        // Define report module permissions
        $reportPermissions = [
            'reports.generate',
            'reports.view',
        ];
        $notificationsPermissions = [
            'notifications.unread',
            'notifications.mark-read',
            'notifications.delete',
        ];

        // Merge all
        $permissions = array_merge(
            $userPermissions,
            $filePermissions,
            $systemPermissions,
            $reportPermissions,
            $notificationsPermissions
        );

        // Create or update permissions
        foreach ($permissions as $perm) {
            Permission::updateOrCreate(
                ['name' => $perm, 'guard_name' => 'api'],
                ['name' => $perm, 'guard_name' => 'api']
            );
        }

        // Define roles with their respective permissions
        $roles = [
            'admin' => $permissions, // all permissions

            'manager' => array_merge(
                $userPermissions,
                [
                    'files.list',
                    'files.create',
                    'files.upload',
                    'files.update',
                    'files.delete',
                    'files.trash',
                    'files.trashed',
                    'files.sync',
                    'files.storage-usage',
                    'files.restore',
                    'files.bulk-restore',
                    'files.bulkDelete',
                    'files.bulkTrash',
                    'files.move',
                    'files.rename',

                    'files.recent',
                    'files.download',
                    'file-permissions.assign',
                    'file-permissions.remove',
                    'file-permissions.list',
                    'file-permissions.user-list',
                    'starred-files.list',
                    'starred-files.toggle',
                ]
            ),

            'user' => [
                'files.list',
                'files.upload',
                'starred-files.list',
                'starred-files.toggle',
                'files.recent',
                'files.download',
            ],
        ];

        // Create roles and assign permissions
        foreach ($roles as $roleName => $perms) {
            $role = Role::updateOrCreate(
                ['name' => $roleName, 'guard_name' => 'api'],
                ['name' => $roleName, 'guard_name' => 'api']
            );

            $role->syncPermissions($perms); // sync permissions
        }
    }
}
