<?php

namespace App\Http\Requests\Concerns;

use App\Support\TransactionCategoryBindingKeys;
use App\Support\TransactionCategoryTypeGuard;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesCompanyTransactionCategoryBindings
{
    /**
     * @return void
     */
    protected function assertCompanyTransactionCategoryBindings(Validator $validator, mixed $bindings): void
    {
        if (! is_array($bindings)) {
            return;
        }

        foreach ($bindings as $entryKey => $binding) {
            [$key, $categoryId] = TransactionCategoryTypeGuard::parseBindingEntry($entryKey, $binding);

            if ($key === '' || $categoryId <= 0) {
                continue;
            }

            if (! TransactionCategoryBindingKeys::has($key)) {
                continue;
            }

            try {
                TransactionCategoryTypeGuard::assertCategoryMatchesBindingKey($key, $categoryId);
            } catch (\RuntimeException $e) {
                $validator->errors()->add('transaction_category_bindings', $e->getMessage());
            }
        }
    }
}
