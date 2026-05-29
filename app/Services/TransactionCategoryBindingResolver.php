<?php

namespace App\Services;

use App\Models\TransactionCategoryBinding;
use App\Support\TransactionCategoryBindingKeys;

class TransactionCategoryBindingResolver
{
    /**
     * @var array<int, array<string, int>>
     */
    private array $cache = [];

    public function resolve(?int $companyId, string $bindingKey, ?int $fallback = null): ?int
    {
        if (! $companyId || ! TransactionCategoryBindingKeys::has($bindingKey)) {
            return $fallback;
        }

        $bindings = $this->forCompany($companyId);

        return isset($bindings[$bindingKey]) ? (int) $bindings[$bindingKey] : $fallback;
    }

    /**
     * @return array<string, int>
     */
    public function forCompany(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        if (! isset($this->cache[$companyId])) {
            $this->cache[$companyId] = TransactionCategoryBinding::query()
                ->where('company_id', $companyId)
                ->pluck('transaction_category_id', 'binding_key')
                ->map(fn ($value) => (int) $value)
                ->toArray();
        }

        return $this->cache[$companyId];
    }
}
