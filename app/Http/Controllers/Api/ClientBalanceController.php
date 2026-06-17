<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreClientBalanceRequest;
use App\Http\Requests\UpdateClientBalanceRequest;
use App\Http\Resources\ClientBalanceResource;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Repositories\TransactionsRepository;
use App\Repositories\ClientBalanceRepository;
use App\Services\CacheService;
use App\Services\ClientBalanceService;
use App\Services\TransactionCategoryBindingResolver;
use App\Support\ClientBalanceViewAccess;
use App\Support\TransactionCategoryBindingKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * @group Клиенты
 * @subgroup Балансы
 */
class ClientBalanceController extends BaseController
{
    public function __construct(
        private readonly ClientBalanceRepository $clientBalanceRepository
    ) {}

    /**
     * Получить все балансы клиента
     *
     * @param int $clientId ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($clientId)
    {
        try {
            $client = $this->clientBalanceRepository->findClientOrFail((int) $clientId);

            $this->authorize('view', $client);
            if (! $this->requireAuthenticatedUser()->can('client_balances_view_all')) {
                return $this->errorResponse(__('У вас нет прав на просмотр балансов клиента'), 403);
            }

            $user = $this->getAuthenticatedUser();
            $balances = $this->clientBalanceRepository->getByClientWithRelations((int) $client->id);
            $balances = ClientBalanceViewAccess::filterBalancesForUser(
                $balances,
                $user,
                $this->getCurrentCompanyId()
            );
            return $this->successResponse(ClientBalanceResource::collection($balances->values())->resolve());
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('client_balance.index.failed', [
                'client_id' => (int) $clientId,
                'company_id' => $this->getCurrentCompanyId(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(__('Ошибка при получении балансов клиента'), 500);
        }
    }

    /**
     * Создать баланс в валюте
     *
     * @param StoreClientBalanceRequest $request
     * @param int $clientId ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreClientBalanceRequest $request, $clientId)
    {
        try {
            $validated = $request->validated();

            $client = $this->clientBalanceRepository->findClientOrFail((int) $clientId);

            $this->authorize('update', $client);
            if (! $this->requireAuthenticatedUser()->can('client_balances_create')) {
                return $this->errorResponse(__('У вас нет прав на создание баланса клиента'), 403);
            }

            $currency = $this->clientBalanceRepository->findCurrencyOrFail((int) $validated['currency_id']);

            $isDefault = $validated['is_default'] ?? false;
            $initialBalance = $validated['balance'] ?? 0;
            $note = $validated['note'] ?? null;
            $type = array_key_exists('type', $validated) ? (int) $validated['type'] : 1;
            $creatorId = $this->getAuthenticatedUserIdOrFail();
            $assigneeIds = array_map('intval', $validated['creator_ids'] ?? []);
            $this->assertValidBalanceAssignees($assigneeIds, $type);

            DB::transaction(function () use ($client, $currency, $isDefault, $initialBalance, $note, $type, $creatorId) {
                $balance = ClientBalanceService::createBalance($client, $currency, $isDefault, $initialBalance, $note, $type);
                $this->createInitialBalanceTransactionIfNeeded($client, $balance, (float) $initialBalance, $creatorId, $note);
            });

            CacheService::invalidateClientsCache();
            CacheService::invalidateClientBalanceCache($clientId);

            $balance = $this->clientBalanceRepository->findLatestByClientAndCurrency((int) $clientId, (int) $currency->id);

            $balance->users()->sync($assigneeIds);
            $balance->load('users:id,name,surname');

            return $this->successResponse(new ClientBalanceResource($balance), __('Баланс создан успешно'), 201);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('client_balance.store.failed', [
                'client_id' => (int) $clientId,
                'company_id' => $this->getCurrentCompanyId(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(__('Ошибка при создании баланса'), 500);
        }
    }

    /**
     * Обновить баланс (установить как дефолтный)
     *
     * @param UpdateClientBalanceRequest $request
     * @param int $clientId ID клиента
     * @param int $id ID баланса
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateClientBalanceRequest $request, $clientId, $id)
    {
        try {
            $validated = $request->validated();

            $balance = $this->clientBalanceRepository->findByClientOrFail((int) $clientId, (int) $id);

            $client = $balance->client;
            $this->authorize('update', $client);
            if (! $this->requireAuthenticatedUser()->can('client_balances_update_all')) {
                return $this->errorResponse(__('У вас нет прав на редактирование баланса клиента'), 403);
            }

            if (($validated['is_default'] ?? false) && !$balance->is_default) {
                $existingDefault = $this->clientBalanceRepository->findOtherDefaultByClient((int) $clientId, (int) $balance->id);

                if ($existingDefault && empty($validated['skip_confirmation'])) {
            return $this->successResponse([
                'requires_confirmation' => true,
                'message' => 'У клиента уже установлен дефолтный баланс в валюте ' . $existingDefault->currency->code . '. Вы уверены, что хотите изменить дефолтный баланс?',
                'current_default' => [
                    'id' => $existingDefault->id,
                    'currency' => [
                        'id' => $existingDefault->currency->id,
                        'code' => $existingDefault->currency->code,
                    ],
                ],
            ]);
                }
            }

            $balanceType = array_key_exists('type', $validated)
                ? (int) $validated['type']
                : (int) $balance->type;

            if (array_key_exists('creator_ids', $validated)) {
                $assigneeIds = array_map('intval', $validated['creator_ids'] ?? []);
                $this->assertValidBalanceAssignees($assigneeIds, $balanceType);
            }

            DB::transaction(function () use ($balance, $validated) {
                if ($validated['is_default'] ?? false) {
                    ClientBalanceService::clearDefaultFlags($balance->client_id, $balance->id);
                }

                $balance->update(array_diff_key($validated, array_flip(['creator_ids', 'skip_confirmation'])));
                if (array_key_exists('creator_ids', $validated)) {
                    $assigneeIds = array_map('intval', $validated['creator_ids'] ?? []);
                    $balance->users()->sync($assigneeIds);
                }
            });

            CacheService::invalidateClientsCache();
            CacheService::invalidateClientBalanceCache($balance->client_id);

            $balance->refresh();
            $balance->load(['currency', 'users:id,name,surname']);

            return $this->successResponse(new ClientBalanceResource($balance), __('Баланс обновлен успешно'));
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('client_balance.update.failed', [
                'client_id' => (int) $clientId,
                'balance_id' => (int) $id,
                'company_id' => $this->getCurrentCompanyId(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(__('Ошибка при обновлении баланса'), 500);
        }
    }

    /**
     * Удалить баланс
     *
     * @param int $clientId ID клиента
     * @param int $id ID баланса
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($clientId, $id)
    {
        try {
            $balance = $this->clientBalanceRepository->findByClientOrFail((int) $clientId, (int) $id);
            $client = $balance->client;
            $this->authorize('update', $client);
            if (! $this->requireAuthenticatedUser()->can('client_balances_delete_all')) {
                return $this->errorResponse(__('У вас нет прав на удаление баланса клиента'), 403);
            }

            if ($balance->is_default) {
                $totalBalances = $this->clientBalanceRepository->countByClient((int) $clientId);
                if ($totalBalances === 1) {
                    return $this->errorResponse(__('Нельзя удалить единственный баланс клиента. У клиента всегда должен быть хотя бы один баланс.'), 422);
                }
                return $this->errorResponse(__('Нельзя удалить дефолтный баланс. Сначала установите другой баланс как дефолтный.'), 422);
            }

            $hasTransactions = $this->clientBalanceRepository->hasActiveTransactions((int) $clientId, (int) $balance->id);

            if ($hasTransactions) {
                return $this->errorResponse(__('Нельзя удалить баланс, если по нему есть транзакции. Удалите сначала все транзакции этого баланса.'), 422);
            }

            DB::transaction(function () use ($balance) {
                $this->clientBalanceRepository->delete($balance);
            });

            CacheService::invalidateClientsCache();
            CacheService::invalidateClientBalanceCache($balance->client_id);

            return $this->successResponse(null, __('Баланс удален успешно'));
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('client_balance.destroy.failed', [
                'client_id' => (int) $clientId,
                'balance_id' => (int) $id,
                'company_id' => $this->getCurrentCompanyId(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(__('Ошибка при удалении баланса'), 500);
        }
    }

    /**
     * Создать долговую транзакцию-основание для начального баланса, если он ненулевой.
     *
     * @param Client $client
     * @param ClientBalance $balance
     * @param float $initialBalance
     * @param int $creatorId
     * @param string|null $note
     * @return void
     * @throws \Exception
     */
    private function createInitialBalanceTransactionIfNeeded(
        Client $client,
        ClientBalance $balance,
        float $initialBalance,
        int $creatorId,
        ?string $note
    ): void {
        if ((float) $initialBalance == 0.0) {
            return;
        }

        $cashRegister = $this->resolveCashRegisterForInitialBalance((int) $balance->type);
        if (!$cashRegister) {
            return;
        }

        $amount = abs((float) $initialBalance);
        $type = $initialBalance > 0 ? 1 : 0;
        $companyId = (int) $client->company_id;
        $bindingResolver = app(TransactionCategoryBindingResolver::class);
        $categoryId = $type === 1
            ? $bindingResolver->require($companyId, TransactionCategoryBindingKeys::ADJUSTMENT_INCOME)
            : $bindingResolver->require($companyId, TransactionCategoryBindingKeys::ADJUSTMENT_OUTCOME);
        $systemNote = 'Начальный баланс клиента';
        if (!empty($note)) {
            $systemNote .= ': ' . $note;
        }

        /** @var TransactionsRepository $transactionsRepository */
        $transactionsRepository = app(TransactionsRepository::class);
        $transactionId = $transactionsRepository->createItem([
            'type' => $type,
            'creator_id' => $creatorId,
            'orig_amount' => $amount,
            'currency_id' => (int) $balance->currency_id,
            'cash_id' => (int) $cashRegister->id,
            'category_id' => $categoryId,
            'project_id' => null,
            'client_id' => (int) $client->id,
            'client_balance_id' => (int) $balance->id,
            'source_type' => null,
            'source_id' => null,
            'note' => $systemNote,
            'date' => now(),
            'is_debt' => true,
            'exchange_rate' => null,
        ], true, true);

        if ($transactionId) {
            $this->clientBalanceRepository->attachBalanceToTransaction((int) $transactionId, (int) $balance->id);
        }
    }

    /**
     * Подобрать кассу для системной начальной транзакции баланса.
     *
     * @param int $balanceType
     * @return CashRegister|null
     */
    private function resolveCashRegisterForInitialBalance(int $balanceType): ?CashRegister
    {
        return $this->clientBalanceRepository->resolveCashRegisterForInitialBalance(
            $this->getCurrentCompanyId(),
            $balanceType
        );
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  int  $balanceType
     * @return void
     */
    private function assertValidBalanceAssignees(array $userIds, int $balanceType): void
    {
        if ($userIds === []) {
            return;
        }

        $invalid = ClientBalanceViewAccess::validateAssigneeUserIds(
            $userIds,
            $balanceType,
            $this->getCurrentCompanyId()
        );

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'creator_ids' => ['Некоторые сотрудники не могут быть назначены на этот счёт.'],
            ]);
        }
    }

}
