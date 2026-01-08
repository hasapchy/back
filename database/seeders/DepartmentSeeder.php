<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\User;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        // Пример департаментов
        $departments = [
            ['title' => 'IT', 'description' => 'Информационные технологии', 'company_id' => 1],
            ['title' => 'HR', 'description' => 'Отдел кадров', 'company_id' => 1],
            ['title' => 'Marketing', 'description' => 'Маркетинг и реклама', 'company_id' => 1],
        ];

        foreach ($departments as $data) {
            $department = Department::create($data);

            // Связь с случайным пользователем (если есть пользователи)
            $user = User::inRandomOrder()->first();
            if ($user) {
                $department->users()->attach($user->id);
            }
        }
    }
}