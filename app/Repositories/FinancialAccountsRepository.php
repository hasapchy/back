<?php

namespace App\Repositories;

use App\Models\FinancialAccount;
use App\Services\CacheService;
use App\Services\FinancialAccountService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FinancialAccountsRepository extends BaseRepository
{
    public function __construct(
        private readonly FinancialAccountService $financialAccountService,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getAccountsWithMetrics(): Collection
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('financial_accounts_list', [$companyId]);
        $from = Carbon::now()->startOfMonth();
        $to = Carbon::now()->endOfMonth();

        return collect(CacheService::getReferenceData($cacheKey, function () use ($companyId, $from, $to) {
            return FinancialAccount::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get()
                ->map(function (FinancialAccount $account) use ($companyId, $from, $to): array {
                    return [
                        'id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'type' => $account->type->value,
                        'balance' => $this->financialAccountService->getBalance($account->id, null, $companyId),
                        'turnover' => $this->financialAccountService->getTurnover($account->id, $from, $to, $companyId),
                    ];
                });
        }));
    }

    /**
     * @param  int  $accountId
     * @return array<string, mixed>|null
     */
    public function getAccountDetails(int $accountId): ?array
    {
        $account = FinancialAccount::query()->where('is_active', true)->find($accountId);
        if (! $account) {
            return null;
        }

        $companyId = $this->getCurrentCompanyId();
        $from = Carbon::now()->startOfMonth();
        $to = Carbon::now()->endOfMonth();

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'balance' => $this->financialAccountService->getBalance($account->id, null, $companyId),
            'turnover' => $this->financialAccountService->getTurnover($account->id, $from, $to, $companyId),
        ];
    }
}
