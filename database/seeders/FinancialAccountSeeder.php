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
            ['code' => '1000', 'name' => 'Cash', 'type' => FinancialAccountType::Asset, 'is_contra' => false],
            ['code' => '1200', 'name' => 'Customers', 'type' => FinancialAccountType::Asset, 'is_contra' => false],
            ['code' => '1500', 'name' => 'Inventory', 'type' => FinancialAccountType::Asset, 'is_contra' => false],
            ['code' => '2000', 'name' => 'Fixed assets', 'type' => FinancialAccountType::Asset, 'is_contra' => false],
            ['code' => '2001', 'name' => 'Accumulated depreciation', 'type' => FinancialAccountType::Asset, 'is_contra' => true],
            ['code' => '2200', 'name' => 'Customer advances', 'type' => FinancialAccountType::Liability, 'is_contra' => false],
            ['code' => '3200', 'name' => 'Suppliers', 'type' => FinancialAccountType::Liability, 'is_contra' => false],
            ['code' => '3300', 'name' => 'Payroll payable', 'type' => FinancialAccountType::Liability, 'is_contra' => false],
            ['code' => '4000', 'name' => 'Sales', 'type' => FinancialAccountType::Income, 'is_contra' => false],
            ['code' => '5000', 'name' => 'Expenses', 'type' => FinancialAccountType::Expense, 'is_contra' => false],
            ['code' => '5001', 'name' => 'Cost of goods sold', 'type' => FinancialAccountType::Expense, 'is_contra' => false],
            ['code' => '5100', 'name' => 'Delivery expense', 'type' => FinancialAccountType::Expense, 'is_contra' => false],
            ['code' => '7600', 'name' => 'Supplier claims', 'type' => FinancialAccountType::Asset, 'is_contra' => false],
            ['code' => '8000', 'name' => 'Equity', 'type' => FinancialAccountType::Equity, 'is_contra' => false],
            ['code' => '9000', 'name' => 'Retained earnings', 'type' => FinancialAccountType::Equity, 'is_contra' => false],
        ];

        foreach ($accounts as $account) {
            FinancialAccount::query()->updateOrCreate(
                ['code' => $account['code']],
                [
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'is_system' => true,
                    'is_active' => true,
                    'is_contra' => $account['is_contra'],
                ]
            );
        }
    }
}
