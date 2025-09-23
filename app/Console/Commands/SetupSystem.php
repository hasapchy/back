<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Unit;

class SetupSystem extends Command
{
    protected $signature = 'system:setup';
    protected $description = 'Быстрая настройка системы - создание базовых данных';

    public function handle()
    {
        $this->info('🚀 Настройка системы...');
        
        // Создаем базовую компанию
        $company = Company::firstOrCreate(
            ['name' => 'Основная компания'],
            ['logo' => 'logo.jpg']
        );
        $this->info("✅ Компания: {$company->name} (ID: {$company->id})");
        
        // Создаем админа если его нет
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Администратор',
                'password' => bcrypt('password'),
                'is_admin' => true
            ]
        );
        $this->info("✅ Админ: {$admin->email} (ID: {$admin->id})");
        
        // Связываем админа с компанией
        if (!$admin->companies()->where('company_id', $company->id)->exists()) {
            $admin->companies()->attach($company->id);
            $this->info("✅ Админ связан с компанией");
        }
        
        // Создаем базовые валюты
        $currencies = [
            ['code' => 'TMT', 'name' => 'Туркменский манат', 'symbol' => 'TMT', 'is_default' => true, 'status' => 1],
            ['code' => 'USD', 'name' => 'Доллар США', 'symbol' => '$', 'is_default' => false, 'status' => 1],
            ['code' => 'EUR', 'name' => 'Евро', 'symbol' => '€', 'is_default' => false, 'status' => 1],
        ];
        
        foreach ($currencies as $currencyData) {
            $currency = Currency::firstOrCreate(
                ['code' => $currencyData['code']],
                $currencyData
            );
            $this->info("✅ Валюта: {$currency->code} - {$currency->name}");
        }
        
        // Создаем базовые единицы измерения
        $units = [
            ['name' => 'Штука', 'short_name' => 'шт'],
            ['name' => 'Килограмм', 'short_name' => 'кг'],
            ['name' => 'Метр', 'short_name' => 'м'],
            ['name' => 'Литр', 'short_name' => 'л'],
        ];
        
        foreach ($units as $unitData) {
            $unit = Unit::firstOrCreate(
                ['short_name' => $unitData['short_name']],
                $unitData
            );
            $this->info("✅ Единица: {$unit->name} ({$unit->short_name})");
        }
        
        $this->info('🎉 Система настроена! Можно входить как admin@example.com / password');
    }
}
