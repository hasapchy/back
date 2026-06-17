<?php

namespace App\Repositories;

use App\Models\Company;
use App\Models\TransactionCategory;
use App\Models\TransactionCategoryBinding;
use App\Support\TransactionCategoryBindingKeys;
use App\Support\TransactionCategoryTypeGuard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CompanyRepository extends BaseRepository
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 10;

        return $this->paginateForIndex($perPage);
    }

    /**
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function paginateForIndex(int $perPage = 10): LengthAwarePaginator
    {
        return Company::query()
            ->select([
                'id', 'name', 'full_name', 'logo',
                'address', 'phone', 'registration_number', 'email', 'warehouse_number',
                'show_deleted_transactions', 'display_decimals', 'rounding_enabled', 'rounding_direction', 'rounding_custom_threshold',
                'rounding_orders_enabled', 'rounding_orders_decimals',
                'rounding_contracts_enabled', 'rounding_contracts_decimals',
                'rounding_warehouse_enabled', 'rounding_warehouse_decimals',
                'rounding_transactions_enabled', 'rounding_transactions_decimals',
                'rounding_quantity_decimals', 'rounding_quantity_enabled', 'rounding_quantity_direction', 'rounding_quantity_custom_threshold',
                'skip_project_order_balance', 'work_schedule', 'ui_theme', 'created_at', 'updated_at',
            ])
            ->with(['transactionCategoryBindings:company_id,binding_key,transaction_category_id'])
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * @param  int  $id
     */
    public function findOrFail(int $id): Company
    {
        return Company::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Company
    {
        return Company::query()->create($data);
    }

    /**
     * @param  Company  $company
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): Company
    {
        $company->update($data);

        return $company;
    }

    /**
     * @param  Company  $company
     * @return void
     */
    public function delete(Company $company): void
    {
        $company->delete();
    }

    /**
     * @return void
     */
    public function loadTransactionCategoryBindings(Company $company): void
    {
        $company->load(['transactionCategoryBindings:company_id,binding_key,transaction_category_id']);
    }

    /**
     * @param  array<string, mixed>|null  $bindings
     * @return void
     */
    public function syncTransactionCategoryBindings(Company $company, ?array $bindings): void
    {
        if ($bindings === null) {
            return;
        }

        $normalized = [];
        foreach ($bindings as $entryKey => $binding) {
            $key = '';
            $categoryId = 0;

            if (is_array($binding)) {
                $key = isset($binding['binding_key']) ? (string) $binding['binding_key'] : '';
                $categoryId = isset($binding['transaction_category_id']) ? (int) $binding['transaction_category_id'] : 0;
            } elseif (is_string($entryKey)) {
                $key = $entryKey;
                $categoryId = (int) $binding;
            }

            if ($key === '' || $categoryId <= 0) {
                continue;
            }

            if (! TransactionCategoryBindingKeys::has($key)) {
                continue;
            }

            TransactionCategoryTypeGuard::assertCategoryMatchesBindingKey($key, $categoryId);

            $normalized[$key] = $categoryId;
        }

        $requestedCategoryIds = array_values($normalized);
        $existingCategoryIds = $requestedCategoryIds === []
            ? []
            : TransactionCategory::query()
                ->whereIn('id', $requestedCategoryIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

        $normalized = array_filter(
            $normalized,
            fn ($categoryId) => in_array((int) $categoryId, $existingCategoryIds, true)
        );

        if ($normalized === []) {
            return;
        }

        TransactionCategoryBinding::query()
            ->where('company_id', $company->id)
            ->delete();

        $rows = [];
        $now = now();
        foreach ($normalized as $key => $categoryId) {
            $rows[] = [
                'company_id' => $company->id,
                'binding_key' => $key,
                'transaction_category_id' => $categoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        TransactionCategoryBinding::query()->insert($rows);
    }
}
