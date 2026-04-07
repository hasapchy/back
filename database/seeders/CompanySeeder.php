<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        Company::whereNull('logo')->orWhere('logo', '')->update(['logo' => 'logo.png']);

        Company::firstOrCreate(
            ['name' => 'Тестовая компания'],
            ['logo' => 'logo.png']
        );
    }
}
