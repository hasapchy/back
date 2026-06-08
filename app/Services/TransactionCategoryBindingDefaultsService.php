<?php

namespace App\Services;

use App\Models\TransactionCategory;
use App\Models\TransactionCategoryBinding;
use App\Support\TransactionCategoryBindingDefaults;
use App\Support\TransactionCategoryBindingKeys;

class TransactionCategoryBindingDefaultsService
{
    /**
     * Добавляет недостающие привязки категорий для компании из TransactionCategoryBindingDefaults.
     */
    public function seedMissingForCompany(int $companyId): void
    {
        if ($companyId <= 0) {
            return;
        }

        $validCategoryIds = TransactionCategory::query()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $existingKeys = TransactionCategoryBinding::query()
            ->where('company_id', $companyId)
            ->pluck('binding_key')
            ->all();

        $now = now();
        $rows = [];

        foreach (TransactionCategoryBindingDefaults::all() as $bindingKey => $defaultCategoryId) {
            if (! TransactionCategoryBindingKeys::has($bindingKey)) {
                continue;
            }
            if (in_array($bindingKey, $existingKeys, true)) {
                continue;
            }
            if (! isset($validCategoryIds[(int) $defaultCategoryId])) {
                continue;
            }

            $rows[] = [
                'company_id' => $companyId,
                'binding_key' => $bindingKey,
                'transaction_category_id' => (int) $defaultCategoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            TransactionCategoryBinding::query()->insert($rows);
        }
    }
}
