<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $rows = [
            ['title' => 'IT', 'description' => 'Информационные технологии'],
            ['title' => 'HR', 'description' => 'Отдел кадров'],
            ['title' => 'Marketing', 'description' => 'Маркетинг и реклама'],
        ];

        foreach ($rows as $row) {
            Department::updateOrCreate(
                [
                    'title' => $row['title'],
                    'company_id' => 1,
                ],
                array_merge($row, [
                    'company_id' => 1,
                ])
            );
        }
    }
}
