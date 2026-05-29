<?php

namespace App\Support;

use App\Models\TransactionCategory;

class TransactionCategoryTypeGuard
{
    public static function matches(int $transactionType, int $categoryType): bool
    {
        return $transactionType === $categoryType;
    }

    /**
     * @return void
     */
    public static function assertMatch(int $transactionType, int $categoryId): void
    {
        if ($categoryId <= 0) {
            return;
        }

        $category = TransactionCategory::query()->find($categoryId);
        if ($category === null) {
            return;
        }

        if (! self::matches($transactionType, (int) $category->type)) {
            throw new \RuntimeException(
                __('Категория не соответствует типу проводки (доход / расход).')
            );
        }
    }

    /**
     * @return void
     */
    public static function assertCategoryMatchesBindingKey(string $bindingKey, int $categoryId): void
    {
        $expectedType = TransactionCategoryBindingKeys::transactionTypeForKey($bindingKey);
        if ($expectedType === null) {
            return;
        }

        self::assertMatch($expectedType, $categoryId);
    }

    /**
     * @return array{0: string, 1: int}
     */
    public static function parseBindingEntry(mixed $entryKey, mixed $binding): array
    {
        if (is_array($binding)) {
            return [
                isset($binding['binding_key']) ? (string) $binding['binding_key'] : '',
                isset($binding['transaction_category_id']) ? (int) $binding['transaction_category_id'] : 0,
            ];
        }

        if (is_string($entryKey)) {
            return [$entryKey, (int) $binding];
        }

        return ['', 0];
    }
}
