<?php

namespace Tests\Support\Concerns;

use App\Models\Company;
use App\Models\TransactionCategory;
use App\Models\TransactionCategoryBinding;
use App\Models\User;
use App\Support\TransactionCategoryBindingKeys;

trait SeedsTransactionCategoryBindings
{
    /**
     * @var array<string, TransactionCategory>
     */
    protected array $transactionCategoryBindingsByKey = [];

    protected function seedStandardTransactionCategoryBindings(Company $company, User $creator): void
    {
        foreach (TransactionCategoryBindingKeys::all() as $bindingKey) {
            $this->seedTransactionCategoryBinding($company, $creator, $bindingKey);
        }
    }

    protected function seedTransactionCategoryBinding(
        Company $company,
        User $creator,
        string $bindingKey,
        ?TransactionCategory $category = null
    ): TransactionCategory {
        $category ??= TransactionCategory::factory()->create([
            'creator_id' => $creator->id,
            'type' => TransactionCategoryBindingKeys::transactionTypeForKey($bindingKey) ?? 0,
        ]);

        TransactionCategoryBinding::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'binding_key' => $bindingKey,
            ],
            ['transaction_category_id' => $category->id],
        );

        $this->transactionCategoryBindingsByKey[$bindingKey] = $category;

        return $category;
    }
}
