<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingSeeder extends Seeder
{
    public function run()
    {
        Setting::updateOrCreate(
            ['setting_name' => 'company_name'],
            ['setting_value' => 'Моя компания']
        );
        Setting::updateOrCreate(
            ['setting_name' => 'company_logo'],
            ['setting_value' => 'Screenshot 2025-08-07 at 17.58.00.png']
        );
    }
}
