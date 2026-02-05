<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Company;
use App\Models\Tenant;

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

        // Для компании без tenant_id создаём tenant (БД тенанта и tenant-миграции по событию TenantCreated)
        if (empty($company->tenant_id)) {
            $tenant = Tenant::on('central')->create([
                'id' => Str::uuid()->toString(),
                'data' => ['company_id' => $company->id],
            ]);
            $company->update(['tenant_id' => $tenant->id]);
            echo "Tenant created for company '{$company->name}' (ID: {$company->id})\n";
        }

        // Привязку админа к компании делаем в AdminSeeder (он выполняется после CompanySeeder).
    }
}
