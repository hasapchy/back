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

    public function resolve(?int $companyId, string $bindingKey): ?int
    {
        if (! $companyId || ! TransactionCategoryBindingKeys::has($bindingKey)) {
            return null;
        }

        $bindings = $this->forCompany($companyId);

        return isset($bindings[$bindingKey]) ? (int) $bindings[$bindingKey] : null;
    }

    public function require(int $companyId, string $bindingKey): int
    {
        if ($companyId <= 0) {
            throw new \RuntimeException((string) __('api.common.company_context_required'));
        }

        $categoryId = $this->resolve($companyId, $bindingKey);
        if ($categoryId === null) {
            throw new \RuntimeException((string) __('api.common.transaction_category_binding_missing', ['key' => $bindingKey]));
        }

        return $categoryId;
    }

    public function isAdjustmentCategory(int $companyId, int $categoryId): bool
    {
        if ($companyId <= 0 || $categoryId <= 0) {
            return false;
        }

        foreach ([TransactionCategoryBindingKeys::ADJUSTMENT_INCOME, TransactionCategoryBindingKeys::ADJUSTMENT_OUTCOME] as $bindingKey) {
            $boundCategoryId = $this->resolve($companyId, $bindingKey);
            if ($boundCategoryId !== null && $boundCategoryId === $categoryId) {
                return true;
            }
        }

        return false;
    }

    public function isEmployeeCategory(int $companyId, int $categoryId): bool
    {
        return in_array($categoryId, $this->employeeCategoryIds($companyId), true);
    }

    /**
     * @return array<int, int>
     */
    public function employeeCategoryIds(int $companyId): array
    {
        return array_values(array_filter([
            $this->resolve($companyId, TransactionCategoryBindingKeys::PRESET_EMPLOYEE_SALARY_PAYMENT),
            $this->resolve($companyId, TransactionCategoryBindingKeys::PRESET_EMPLOYEE_ADVANCE),
            $this->resolve($companyId, TransactionCategoryBindingKeys::PRESET_EMPLOYEE_SALARY_ACCRUAL),
            $this->resolve($companyId, TransactionCategoryBindingKeys::PRESET_EMPLOYEE_BONUS),
            $this->resolve($companyId, TransactionCategoryBindingKeys::PRESET_EMPLOYEE_PENALTY),
        ], fn ($id) => $id !== null));
    }

    /**
     * @return array{advance: int, bonus: int, penalty: int, salary_accrual: int, salary_payment: int}
     */
    public function requireEmployeeCategoryIds(int $companyId): array
    {
        return [
            'advance' => $this->require($companyId, TransactionCategoryBindingKeys::PRESET_EMPLOYEE_ADVANCE),
            'bonus' => $this->require($companyId, TransactionCategoryBindingKeys::PRESET_EMPLOYEE_BONUS),
            'penalty' => $this->require($companyId, TransactionCategoryBindingKeys::PRESET_EMPLOYEE_PENALTY),
            'salary_accrual' => $this->require($companyId, TransactionCategoryBindingKeys::PRESET_EMPLOYEE_SALARY_ACCRUAL),
            'salary_payment' => $this->require($companyId, TransactionCategoryBindingKeys::PRESET_EMPLOYEE_SALARY_PAYMENT),
        ];
    }
}
