<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\User;
use App\Models\Permission;

class RoleAndAdminSeeder extends Seeder
{
    public function run()
    {
      
        $adminRole = Role::updateOrCreate(['name' => 'admin']);
        $permissions = Permission::all();
        $adminRole->permissions()->sync($permissions->pluck('id'));

        // Create admin user
        $adminUser = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => bcrypt('12345678'),
                'is_active' => true,
            ]
        );

        $adminUser->roles()->sync([$adminRole->id]);
    }
}
