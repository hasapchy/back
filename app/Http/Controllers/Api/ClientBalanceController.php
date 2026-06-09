<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ClientBalanceResource;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\Transaction;
use App\Repositories\TransactionsRepository;
use App\Services\CacheService;
use App\Services\ClientBalanceService;
use App\Services\TransactionCategoryBindingResolver;
use App\Support\ClientBalanceViewAccess;
use App\Support\TransactionCategoryBindingKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @group Клиенты
 * @subgroup Балансы
 */
class ClientBalanceController extends BaseController
{
    /**
     * Получить все балансы клиента
     *
     * @param int $clientId ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($clientId)
    {
        try {
            $client = Client::findOrFail($clientId);

            $this->authorize('view', $client);
            if (! $this->requireAuthenticatedUser()->can('client_balances_view_all')) {
                return $this->errorResponse(__('У вас нет прав на просмотр балансов клиента'), 403);
            }

            $user = $this->getAuthenticatedUser();
            $balances = $client->balances()->with(['currency', 'users:id,name,surname'])->get();
            $balances = ClientBalanceViewAccess::filterBalancesForUser(
                $balances,
                $user,
                $this->getCurrentCompanyId()
            );
            $balancesData = $balances->values()->map(fn ($balance) => $this->formatBalanceResponse($balance))->all();

            return $this->successResponse(ClientBalanceResource::collection($balancesData)->resolve());
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при получении балансов клиента: ') . $e->getMessage(), 500);
        }
    }

    /**
     * Создать баланс в валюте
     *
     * @param Request $request
     * @param int $clientId ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, $clientId)
    {
        try {
            $validated = $request->validate([
                'currency_id' => 'required|exists:currencies,id',
                'type' => 'nullable|integer|in:0,1',
                'is_default' => 'boolean',
                'balance' => 'nullable|numeric',
                'note' => 'nullable|string',
                'creator_ids' => 'nullable|array',
                'creator_ids.*' => 'exists:users,id',
            ]);

            $client = Client::findOrFail($clientId);

            $this->authorize('update', $client);
            if (! $this->requireAuthenticatedUser()->can('client_balances_create')) {
                return $this->errorResponse(__('У вас нет прав на создание баланса клиента'), 403);
            }

            $currency = Currency::findOrFail($validated['currency_id']);

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

            $balance = ClientBalance::where('client_id', $clientId)
                ->where('currency_id', $currency->id)
                ->orderBy('id', 'desc')
                ->with(['currency', 'users:id,name,surname'])
                ->first();

            $balance->users()->sync($assigneeIds);
            $balance->load('users:id,name,surname');

            return $this->successResponse(new ClientBalanceResource($this->formatBalanceResponse($balance)), __('Баланс создан успешно'), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при создании баланса: ') . $e->getMessage(), 500);
        }
    }

    /**
     * Обновить баланс (установить как дефолтный)
     *
     * @param Request $request
     * @param int $clientId ID клиента
     * @param int $id ID баланса
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $clientId, $id)
    {
        try {
            $validated = $request->validate([
                'type' => 'nullable|integer|in:0,1',
                'is_default' => 'boolean',
                'skip_confirmation' => 'boolean',
                'note' => 'nullable|string',
                'creator_ids' => 'nullable|array',
                'creator_ids.*' => 'exists:users,id',
            ]);

            $balance = ClientBalance::where('client_id', $clientId)
                ->with(['currency', 'users:id,name,surname'])
                ->findOrFail($id);

            $client = $balance->client;
            $this->authorize('update', $client);
            if (! $this->requireAuthenticatedUser()->can('client_balances_update_all')) {
                return $this->errorResponse(__('У вас нет прав на редактирование баланса клиента'), 403);
            }

            if (($validated['is_default'] ?? false) && !$balance->is_default) {
                $existingDefault = ClientBalance::where('client_id', $clientId)
                    ->where('id', '!=', $balance->id)
                    ->where('is_default', true)
                    ->with('currency')
                    ->first();

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

            return $this->successResponse(new ClientBalanceResource($this->formatBalanceResponse($balance)), __('Баланс обновлен успешно'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при обновлении баланса: ') . $e->getMessage(), 500);
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
            $balance = ClientBalance::where('client_id', $clientId)->findOrFail($id);
            $client = $balance->client;
            $this->authorize('update', $client);
            if (! $this->requireAuthenticatedUser()->can('client_balances_delete_all')) {
                return $this->errorResponse(__('У вас нет прав на удаление баланса клиента'), 403);
            }

            if ($balance->is_default) {
                $totalBalances = ClientBalance::where('client_id', $clientId)->count();
                if ($totalBalances === 1) {
                    return $this->errorResponse(__('Нельзя удалить единственный баланс клиента. У клиента всегда должен быть хотя бы один баланс.'), 422);
                }
                return $this->errorResponse(__('Нельзя удалить дефолтный баланс. Сначала установите другой баланс как дефолтный.'), 422);
            }

            $hasTransactions = Transaction::where('client_id', $clientId)
                ->where('client_balance_id', $balance->id)
                ->where('is_deleted', false)
                ->exists();

            if ($hasTransactions) {
                return $this->errorResponse(__('Нельзя удалить баланс, если по нему есть транзакции. Удалите сначала все транзакции этого баланса.'), 422);
            }

            DB::transaction(function () use ($balance) {
                $balance->delete();
            });

            CacheService::invalidateClientsCache();
            CacheService::invalidateClientBalanceCache($balance->client_id);

            return $this->successResponse(null, __('Баланс удален успешно'));
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при удалении баланса: ') . $e->getMessage(), 500);
        }
    }

    /**
     * Формат ответа с данными баланса и списком пользователей
     *
     * @param \App\Models\ClientBalance $balance
     * @return array
     */
    private function formatBalanceResponse(ClientBalance $balance): array
    {
        $users = ($balance->users ?? collect())->map(fn ($u) => [
            'id' => $u->id,
            'name' => trim(($u->name ?? '') . ' ' . ($u->surname ?? '')),
        ])->values()->all();

        return [
            'id' => $balance->id,
            'currency_id' => $balance->currency_id,
                'type' => (int) $balance->type,
            'currency' => $balance->currency ? [
                'id' => $balance->currency->id,
                'code' => $balance->currency->code,
                'name' => $balance->currency->name,
            ] : null,
            'balance' => (float) $balance->balance,
            'is_default' => $balance->is_default,
            'note' => $balance->note,
            'users' => $users,
        ];
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
            Transaction::where('id', (int) $transactionId)
                ->update(['client_balance_id' => (int) $balance->id]);
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
        $companyId = $this->getCurrentCompanyId();
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
