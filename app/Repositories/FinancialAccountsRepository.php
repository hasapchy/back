<?php

namespace App\Repositories;

use App\Models\FinancialAccount;
use App\Services\AccountBalanceService;
use App\Services\CacheService;
use App\Services\FinancialAccountService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FinancialAccountsRepository extends BaseRepository
{
    public function __construct(
        private readonly FinancialAccountService $financialAccountService,
        private readonly AccountBalanceService $accountBalanceService,
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
        $useJournal = (bool) config('journal.use_for_balances', true);

        return collect(CacheService::getReferenceData($cacheKey, function () use ($companyId, $from, $to, $useJournal) {
            return FinancialAccount::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get()
                ->map(function (FinancialAccount $account) use ($companyId, $from, $to, $useJournal): array {
                    $balance = $useJournal
                        ? $this->accountBalanceService->getBalance($account->id, $companyId)
                        : $this->financialAccountService->getBalance($account->id, null, $companyId);
                    $turnover = $useJournal
                        ? $this->accountBalanceService->getTurnover($account->id, $companyId, $from, $to)
                        : $this->financialAccountService->getTurnover($account->id, $from, $to, $companyId);

                    return [
                        'id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'type' => $account->type->value,
                        'balance' => $balance,
                        'turnover' => $turnover,
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
        $useJournal = (bool) config('journal.use_for_balances', true);

        $balance = $useJournal
            ? $this->accountBalanceService->getBalance($account->id, $companyId)
            : $this->financialAccountService->getBalance($account->id, null, $companyId);
        $turnover = $useJournal
            ? $this->accountBalanceService->getTurnover($account->id, $companyId, $from, $to)
            : $this->financialAccountService->getTurnover($account->id, $from, $to, $companyId);

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'balance' => $balance,
            'turnover' => $turnover,
        ];
    }
}
