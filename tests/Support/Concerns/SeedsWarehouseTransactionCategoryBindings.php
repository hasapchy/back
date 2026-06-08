<?php

namespace Tests\Support\Concerns;

use App\Models\Company;
use App\Models\TransactionCategory;
use App\Models\TransactionCategoryBinding;
use App\Models\User;
use App\Support\TransactionCategoryBindingKeys;

trait SeedsWarehouseTransactionCategoryBindings
{
    use SeedsTransactionCategoryBindings;

    protected TransactionCategory $warehouseGoodsPaymentCategory;

    protected TransactionCategory $warehouseDeliveryExpenseCategory;

    protected function seedWarehouseGoodsPaymentBindings(Company $company, User $creator, ?TransactionCategory $category = null): void
    {
        $this->seedStandardTransactionCategoryBindings($company, $creator);

        $this->warehouseGoodsPaymentCategory = $category ?? TransactionCategory::factory()->create([
            'creator_id' => $creator->id,
            'type' => 0,
        ]);

        foreach ([
            TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT,
            TransactionCategoryBindingKeys::WAREHOUSE_PURCHASE,
        ] as $bindingKey) {
            TransactionCategoryBinding::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'binding_key' => $bindingKey,
                ],
                ['transaction_category_id' => $this->warehouseGoodsPaymentCategory->id],
            );
            $this->transactionCategoryBindingsByKey[$bindingKey] = $this->warehouseGoodsPaymentCategory;
        }
    }

    protected function seedWarehouseDeliveryExpenseBinding(Company $company, User $creator): void
    {
        $this->warehouseDeliveryExpenseCategory = TransactionCategory::factory()->create([
            'creator_id' => $creator->id,
            'type' => 0,
        ]);

        TransactionCategoryBinding::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'binding_key' => TransactionCategoryBindingKeys::PRESET_WAREHOUSE_RECEIPT_DELIVERY_EXPENSE,
            ],
            ['transaction_category_id' => $this->warehouseDeliveryExpenseCategory->id],
        );
        $this->transactionCategoryBindingsByKey[TransactionCategoryBindingKeys::PRESET_WAREHOUSE_RECEIPT_DELIVERY_EXPENSE] = $this->warehouseDeliveryExpenseCategory;
    }
}
