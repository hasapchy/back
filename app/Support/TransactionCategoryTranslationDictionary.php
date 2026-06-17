<?php

namespace App\Support;

use App\Models\TransactionCategory;
use App\Services\CacheService;

class TransactionCategoryTranslationDictionary
{
    public const DOMAIN = 'transactionCategory';

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return CacheService::getReferenceData('transaction_category_translation_keys', function () {
            return TransactionCategory::query()
                ->orderBy('id')
                ->pluck('name')
                ->filter(static fn ($name) => is_string($name) && $name !== '')
                ->map(static fn (string $name) => self::buildKey($name))
                ->values()
                ->all();
        });
    }

    /**
     * @param string $slug
     * @return string
     */
    public static function buildKey(string $slug): string
    {
        return self::DOMAIN.'.'.$slug;
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
