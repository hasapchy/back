<?php

namespace Database\Seeders;

use App\Enums\FinancialAccountMovementDirection;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountRule;
use App\Support\TransactionCategoryBindingKeys;
use Illuminate\Database\Seeder;

class FinancialAccountRuleSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $this->call(FinancialAccountSeeder::class);

        $accounts = FinancialAccount::query()
            ->whereIn('code', ['1000', '1200', '3200', '5100', '7600'])
            ->pluck('id', 'code');

        $rules = [
            [
                'binding_key' => TransactionCategoryBindingKeys::ORDER,
                'category_id' => null,
                'source_type' => null,
                'type' => 1,
                'is_debt' => true,
                'financial_account_id' => $accounts['1200'],
                'direction' => FinancialAccountMovementDirection::Increase,
                'priority' => 100,
                'stop_processing' => false,
            ],
            [
                'binding_key' => TransactionCategoryBindingKeys::ORDER,
                'category_id' => null,
                'source_type' => null,
                'type' => 1,
                'is_debt' => false,
                'financial_account_id' => $accounts['1000'],
                'direction' => FinancialAccountMovementDirection::Increase,
                'priority' => 100,
                'stop_processing' => false,
            ],
            [
                'binding_key' => TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT,
                'category_id' => null,
                'source_type' => null,
                'type' => 0,
                'is_debt' => true,
                'financial_account_id' => $accounts['3200'],
                'direction' => FinancialAccountMovementDirection::Increase,
                'priority' => 100,
                'stop_processing' => false,
            ],
            [
                'binding_key' => TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT,
                'category_id' => null,
                'source_type' => null,
                'type' => 0,
                'is_debt' => false,
                'financial_account_id' => $accounts['1000'],
                'direction' => FinancialAccountMovementDirection::Decrease,
                'priority' => 90,
                'stop_processing' => false,
            ],
            [
                'binding_key' => TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT,
                'category_id' => null,
                'source_type' => null,
                'type' => 0,
                'is_debt' => false,
                'financial_account_id' => $accounts['3200'],
                'direction' => FinancialAccountMovementDirection::Decrease,
                'priority' => 80,
                'stop_processing' => false,
            ],
            [
                'binding_key' => TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION,
                'category_id' => null,
                'source_type' => null,
                'type' => 1,
                'is_debt' => true,
                'financial_account_id' => $accounts['3200'],
                'direction' => FinancialAccountMovementDirection::Decrease,
                'priority' => 100,
                'stop_processing' => false,
            ],
            [
                'binding_key' => TransactionCategoryBindingKeys::WAREHOUSE_RETURN_SUPPLIER_CREDIT,
                'category_id' => null,
                'source_type' => null,
                'type' => 1,
                'is_debt' => true,
                'financial_account_id' => $accounts['7600'],
                'direction' => FinancialAccountMovementDirection::Increase,
                'priority' => 100,
                'stop_processing' => false,
            ],
            [
                'binding_key' => TransactionCategoryBindingKeys::PRESET_WAREHOUSE_RECEIPT_DELIVERY_EXPENSE,
                'category_id' => null,
                'source_type' => null,
                'type' => 0,
                'is_debt' => null,
                'financial_account_id' => $accounts['5100'],
                'direction' => FinancialAccountMovementDirection::Increase,
                'priority' => 100,
                'stop_processing' => false,
            ],
        ];

        foreach ($rules as $rule) {
            FinancialAccountRule::query()->updateOrCreate(
                [
                    'binding_key' => $rule['binding_key'],
                    'category_id' => $rule['category_id'],
                    'type' => $rule['type'],
                    'is_debt' => $rule['is_debt'],
                    'financial_account_id' => $rule['financial_account_id'],
                    'direction' => $rule['direction'],
                ],
                [
                    'source_type' => $rule['source_type'],
                    'priority' => $rule['priority'],
                    'stop_processing' => $rule['stop_processing'],
                ]
            );
        }
    }
}
