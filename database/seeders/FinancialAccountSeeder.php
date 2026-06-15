<?php

namespace Database\Seeders;

use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use Illuminate\Database\Seeder;

class FinancialAccountSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash', 'type' => FinancialAccountType::Asset],
            ['code' => '1200', 'name' => 'Customers', 'type' => FinancialAccountType::Asset],
            ['code' => '1500', 'name' => 'Inventory', 'type' => FinancialAccountType::Asset],
            ['code' => '3200', 'name' => 'Suppliers', 'type' => FinancialAccountType::Liability],
            ['code' => '4000', 'name' => 'Sales', 'type' => FinancialAccountType::Income],
            ['code' => '5000', 'name' => 'Expenses', 'type' => FinancialAccountType::Expense],
            ['code' => '5100', 'name' => 'Delivery expense', 'type' => FinancialAccountType::Expense],
            ['code' => '7600', 'name' => 'Supplier claims', 'type' => FinancialAccountType::Asset],
        ];

        foreach ($accounts as $account) {
            FinancialAccount::query()->updateOrCreate(
                ['code' => $account['code']],
                [
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'is_system' => true,
                    'is_active' => true,
                ]
            );
        }
    }
}
