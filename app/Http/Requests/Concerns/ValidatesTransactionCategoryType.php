<?php

namespace App\Http\Requests\Concerns;

use App\Support\TransactionCategoryTypeGuard;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesTransactionCategoryType
{
    /**
     * @return void
     */
    protected function assertTransactionCategoryMatchesType(
        Validator $validator,
        ?int $transactionType,
        ?int $categoryId,
    ): void {
        if ($transactionType === null || $categoryId === null || $categoryId <= 0) {
            return;
        }

        try {
            TransactionCategoryTypeGuard::assertMatch($transactionType, $categoryId);
        } catch (\RuntimeException $e) {
            $validator->errors()->add('category_id', $e->getMessage());
        }
    }
}
