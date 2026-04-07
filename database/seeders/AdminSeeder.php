<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $admin = User::query()->orderBy('id')->first();

        $credentials = [
            'password' => Hash::make('12345678'),
            'is_active' => true,
            'is_admin' => true,
        ];

        if ($admin) {
            $admin->update($credentials);
        } else {
            $admin = User::query()->create(array_merge($credentials, [
                'email' => 'admin@example.com',
                'name' => 'Admin',
            ]));
        }

        $firstCompany = Company::query()->orderBy('id')->first();
        if ($firstCompany && !$admin->companies()->whereKey($firstCompany->id)->exists()) {
            $admin->companies()->attach($firstCompany->id);
        }

        $admin->load('companies');

        foreach ($admin->companies as $c) {
            foreach (['admin', 'basement_worker'] as $roleName) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', 'api')
                    ->where('company_id', $c->id)
                    ->first();

                if (!$role) {
                    continue;
                }

                $exists = DB::table('company_user_role')
                    ->where('company_id', $c->id)
                    ->where('creator_id', $admin->id)
                    ->where('role_id', $role->id)
                    ->exists();

                if (!$exists) {
                    DB::table('company_user_role')->insert([
                        'company_id' => $c->id,
                        'creator_id' => $admin->id,
                        'role_id' => $role->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
