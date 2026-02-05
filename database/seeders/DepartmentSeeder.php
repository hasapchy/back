<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Department;
use App\Models\User;

class DepartmentSeeder extends Seeder
{
    /**
     * Сидер для tenant-БД. Пропускает выполнение в центральном контексте.
     * attach() не используется: User в central, pivot department_user в tenant.
     */
    public function run(): void
    {
        if (!Schema::hasTable('departments')) {
            return;
        }

        $departments = [
            ['title' => 'IT', 'description' => 'Информационные технологии', 'company_id' => 1],
            ['title' => 'HR', 'description' => 'Отдел кадров', 'company_id' => 1],
            ['title' => 'Marketing', 'description' => 'Маркетинг и реклама', 'company_id' => 1],
        ];

        foreach ($departments as $data) {
            $department = Department::create($data);

            $user = User::inRandomOrder()->first();
            if ($user && Schema::hasTable('department_user')) {
                $now = now();
                DB::table('department_user')->insertOrIgnore([
                    'department_id' => $department->id,
                    'user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
