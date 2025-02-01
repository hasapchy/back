<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingSeeder extends Seeder
{
    public function run()
    {
        Setting::create(['setting_name' => 'company_name', 'setting_value' => 'Laravel Company']);
        Setting::create(['setting_name' => 'company_logo', 'setting_value' => '/images/laravel.png']);
        Setting::create(['setting_name' => 'source', 'setting_value' => 'Default Source']);
    }
}
