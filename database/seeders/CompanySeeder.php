<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\User;

class CompanySeeder extends Seeder
{
    public function run()
    {
        // Обновляем все существующие компании, у которых нет логотипа
        Company::whereNull('logo')->orWhere('logo', '')->update(['logo' => 'logo.png']);

        // Создаем тестовую компанию, если её нет
        $company = Company::firstOrCreate(
            ['name' => 'Тестовая компания'],
            ['logo' => 'logo.png']
        );

        // Связываем компанию с администратором
        $admin = User::where('email', 'admin@example.com')->first();
        if ($admin && !$admin->companies()->where('company_id', $company->id)->exists()) {
            $admin->companies()->attach($company->id);
            echo "Company '{$company->name}' linked to admin user\n";
        }
    }
}
