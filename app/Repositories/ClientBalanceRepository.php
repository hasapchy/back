<?php

namespace App\Repositories;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ClientBalanceRepository extends BaseRepository
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : 0;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return ClientBalance::query()
            ->where('client_id', $clientId)
            ->with(['currency', 'users:id,name,surname'])
            ->paginate($perPage);
    }

    /**
     * @param  int  $clientId
     */
    public function findClientSummary(int $clientId): ?Client
    {
        return Client::query()
            ->select(['id', 'first_name', 'last_name', 'patronymic', 'company_id'])
            ->find($clientId);
    }

    /**
     * @param  int  $clientId
     * @return Collection<int, ClientBalance>
     */
    public function getByClientWithRelations(int $clientId): Collection
    {
        return ClientBalance::query()
            ->where('client_id', $clientId)
            ->with(['currency', 'users:id,name,surname'])
            ->get();
    }

    /**
     * @param  int  $clientId
     * @return Collection<int, ClientBalance>
     */
    public function getClientBalancesForVerification(int $clientId): Collection
    {
        return ClientBalance::query()
            ->where('client_id', $clientId)
            ->with('currency:id,code,name')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  int  $clientId
     * @param  int  $balanceId
     */
    public function findByClientOrFail(int $clientId, int $balanceId): ClientBalance
    {
        return ClientBalance::query()
            ->where('client_id', $clientId)
            ->with(['currency', 'users:id,name,surname'])
            ->findOrFail($balanceId);
    }

    /**
     * @param  int  $clientId
     */
    public function findClientOrFail(int $clientId): Client
    {
        return Client::query()->findOrFail($clientId);
    }

    /**
     * @param  int  $currencyId
     */
    public function findCurrencyOrFail(int $currencyId): Currency
    {
        return Currency::query()->findOrFail($currencyId);
    }

    /**
     * @param  int  $clientId
     * @param  int  $currencyId
     */
    public function findLatestByClientAndCurrency(int $clientId, int $currencyId): ?ClientBalance
    {
        return ClientBalance::query()
            ->where('client_id', $clientId)
            ->where('currency_id', $currencyId)
            ->orderByDesc('id')
            ->with(['currency', 'users:id,name,surname'])
            ->first();
    }

    /**
     * @param  int  $clientId
     */
    public function countByClient(int $clientId): int
    {
        return ClientBalance::query()->where('client_id', $clientId)->count();
    }

    /**
     * @param  int  $clientId
     * @param  int  $exceptBalanceId
     */
    public function findOtherDefaultByClient(int $clientId, int $exceptBalanceId): ?ClientBalance
    {
        return ClientBalance::query()
            ->where('client_id', $clientId)
            ->where('id', '!=', $exceptBalanceId)
            ->where('is_default', true)
            ->with('currency')
            ->first();
    }

    /**
     * @param  int  $clientId
     * @param  int  $balanceId
     */
    public function hasActiveTransactions(int $clientId, int $balanceId): bool
    {
        return Transaction::query()
            ->where('client_id', $clientId)
            ->where('client_balance_id', $balanceId)
            ->where('is_deleted', false)
            ->exists();
    }

    /**
     * @param  int|null  $companyId
     * @param  int  $balanceType
     */
    public function resolveCashRegisterForInitialBalance(?int $companyId, int $balanceType): ?CashRegister
    {
        $isCash = $balanceType === 1;
        $query = CashRegister::query();

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $byType = (clone $query)
            ->where('is_cash', $isCash)
            ->orderBy('id')
            ->first();

        if ($byType) {
            return $byType;
        }

        return (clone $query)->orderBy('id')->first();
    }

    /**
     * @param  int  $transactionId
     * @param  int  $balanceId
     * @return void
     */
    public function attachBalanceToTransaction(int $transactionId, int $balanceId): void
    {
        Transaction::query()
            ->where('id', $transactionId)
            ->update(['client_balance_id' => $balanceId]);
    }

    /**
     * @param  ClientBalance  $balance
     * @return void
     */
    public function delete(ClientBalance $balance): void
    {
        $balance->delete();
    }

    /**
     * @param  int  $clientId
     * @param  int  $balanceId
     */
    public function findForClientWithLock(int $clientId, int $balanceId): ?ClientBalance
    {
        return ClientBalance::query()
            ->where('client_id', $clientId)
            ->where('id', $balanceId)
            ->lockForUpdate()
            ->first();
    }
}
