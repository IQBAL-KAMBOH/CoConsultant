<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        // Default Admin User
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Super Admin',
                'user_type' => 'admin',
                'password' => Hash::make('password123'),
            ]
        );
        $admin->syncRoles(['admin']); // assign admin role

        // Default Manager User
        $manager = User::updateOrCreate(
            ['email' => 'manager@example.com'],
            [
                'name' => 'Project Manager',
                'user_type' => 'manager',
                'password' => Hash::make('password123'),
            ]
        );
        $manager->syncRoles(['manager']); // assign manager role

        // Default Normal User
        $user = User::updateOrCreate(
            ['email' => 'user@example.com'],
            [
                'name' => 'Basic User',
                'user_type' => 'user',
                'password' => Hash::make('password123'),
            ]
        );
        $user->syncRoles(['user']); // assign user role
    }
}
