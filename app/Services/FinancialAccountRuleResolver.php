<?php

namespace App\Services;

use App\Models\FinancialAccountRule;
use App\Models\Transaction;
use App\Models\TransactionCategoryBinding;
use Illuminate\Support\Collection;

class FinancialAccountRuleResolver
{
    /**
     * @param  Transaction  $transaction
     * @return Collection<int, FinancialAccountRule>
     */
    public function resolve(Transaction $transaction): Collection
    {
        $companyId = $this->resolveCompanyId($transaction);
        if (! $companyId) {
            return collect();
        }

        $bindingKeys = $this->resolveBindingKeys($companyId, (int) $transaction->category_id);
        if ($bindingKeys === []) {
            return collect();
        }

        $rules = FinancialAccountRule::query()
            ->where(function ($query) use ($bindingKeys): void {
                $query->whereIn('binding_key', $bindingKeys)
                    ->orWhereNull('binding_key');
            })
            ->where(function ($query) use ($transaction): void {
                $query->whereNull('category_id')
                    ->orWhere('category_id', $transaction->category_id);
            })
            ->where(function ($query) use ($transaction): void {
                $query->whereNull('source_type')
                    ->orWhere('source_type', $transaction->source_type);
            })
            ->where(function ($query) use ($transaction): void {
                $query->whereNull('type')
                    ->orWhere('type', $transaction->type);
            })
            ->where(function ($query) use ($transaction): void {
                if ($transaction->is_debt === null) {
                    $query->whereNull('is_debt');
                } else {
                    $query->where(function ($inner) use ($transaction): void {
                        $inner->whereNull('is_debt')
                            ->orWhere('is_debt', (bool) $transaction->is_debt);
                    });
                }
            })
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $matched = collect();
        foreach ($rules as $rule) {
            if ($rule->binding_key !== null && ! in_array($rule->binding_key, $bindingKeys, true)) {
                continue;
            }

            $matched->push($rule);

            if ($rule->stop_processing) {
                break;
            }
        }

        return $matched;
    }

    /**
     * @param  Transaction  $transaction
     * @return int|null
     */
    private function resolveCompanyId(Transaction $transaction): ?int
    {
        if ($transaction->company_id) {
            return (int) $transaction->company_id;
        }

        $transaction->loadMissing('cashRegister:id,company_id');

        return $transaction->cashRegister?->company_id
            ? (int) $transaction->cashRegister->company_id
            : null;
    }

    /**
     * @param  int  $companyId
     * @param  int  $categoryId
     * @return array<int, string>
     */
    private function resolveBindingKeys(int $companyId, int $categoryId): array
    {
        return TransactionCategoryBinding::query()
            ->where('company_id', $companyId)
            ->where('transaction_category_id', $categoryId)
            ->pluck('binding_key')
            ->map(static fn ($key) => (string) $key)
            ->unique()
            ->values()
            ->all();
    }
}
