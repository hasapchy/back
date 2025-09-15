<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;

class CompanySeeder extends Seeder
{
    public function run()
    {
        // Обновляем все существующие компании, у которых нет логотипа
        Company::whereNull('logo')->orWhere('logo', '')->update(['logo' => 'logo.jpg']);
    }
}
