<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Transaction;
use App\Services\CacheService;
use App\Services\ClientBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            
            if (!$this->canPerformAction('clients', 'view', $client)) {
                return $this->forbiddenResponse('У вас нет прав на просмотр балансов этого клиента');
            }
            
            $balances = $client->balances()->with('currency')->get();

            $balancesData = $balances->map(function ($balance) {
                return [
                    'id' => $balance->id,
                    'currency_id' => $balance->currency_id,
                    'currency' => [
                        'id' => $balance->currency->id,
                        'code' => $balance->currency->code,
                        'symbol' => $balance->currency->symbol,
                        'name' => $balance->currency->name,
                    ],
                    'balance' => (float) $balance->balance,
                    'is_default' => $balance->is_default,
                    'note' => $balance->note,
                ];
            })->values()->all();

            return $this->successResponse($balancesData);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении балансов клиента: ' . $e->getMessage(), 500);
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
                'is_default' => 'boolean',
                'balance' => 'nullable|numeric',
                'note' => 'nullable|string',
            ]);

            $client = Client::findOrFail($clientId);
            
            if (!$this->canPerformAction('clients', 'update', $client)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого клиента');
            }
            
            $currency = Currency::findOrFail($validated['currency_id']);

            $isDefault = $validated['is_default'] ?? false;
            $initialBalance = $validated['balance'] ?? 0;
            $note = $validated['note'] ?? null;

            DB::transaction(function () use ($client, $currency, $isDefault, $initialBalance, $note) {
                ClientBalanceService::createBalance($client, $currency, $isDefault, $initialBalance, $note);
            });

            CacheService::invalidateClientsCache();
            CacheService::invalidateClientBalanceCache($clientId);

            $balance = ClientBalance::where('client_id', $clientId)
                ->where('currency_id', $currency->id)
                ->orderBy('id', 'desc')
                ->with('currency')
                ->first();

            return $this->successResponse([
                'id' => $balance->id,
                'currency_id' => $balance->currency_id,
                'currency' => [
                    'id' => $balance->currency->id,
                    'code' => $balance->currency->code,
                    'symbol' => $balance->currency->symbol,
                    'name' => $balance->currency->name,
                ],
                'balance' => (float) $balance->balance,
                'is_default' => $balance->is_default,
                'note' => $balance->note,
            ], 'Баланс создан успешно', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при создании баланса: ' . $e->getMessage(), 500);
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
                'is_default' => 'boolean',
                'skip_confirmation' => 'boolean',
                'note' => 'nullable|string',
            ]);

            $balance = ClientBalance::where('client_id', $clientId)
                ->with('currency')
                ->findOrFail($id);
            
            $client = $balance->client;
            if (!$this->canPerformAction('clients', 'update', $client)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого клиента');
            }

            if (!empty($validated['is_default']) && $validated['is_default'] && !$balance->is_default) {
                $existingDefault = ClientBalance::where('client_id', $clientId)
                    ->where('id', '!=', $balance->id)
                    ->where('is_default', true)
                    ->with('currency')
                    ->first();

                if ($existingDefault && empty($validated['skip_confirmation'])) {
            return response()->json([
                'requires_confirmation' => true,
                'message' => 'У клиента уже установлен дефолтный баланс в валюте ' . $existingDefault->currency->symbol . '. Вы уверены, что хотите изменить дефолтный баланс?',
                'current_default' => [
                    'id' => $existingDefault->id,
                    'currency' => [
                        'id' => $existingDefault->currency->id,
                        'symbol' => $existingDefault->currency->symbol,
                    ],
                ],
            ], 200);
                }
            }

            DB::transaction(function () use ($balance, $validated) {
                if (!empty($validated['is_default']) && $validated['is_default']) {
                    ClientBalanceService::clearDefaultFlags($balance->client_id, $balance->id);
                }

                $balance->update($validated);
            });

            CacheService::invalidateClientsCache();
            CacheService::invalidateClientBalanceCache($balance->client_id);

            $balance->refresh();
            $balance->load('currency');

            return $this->successResponse([
                'id' => $balance->id,
                'currency_id' => $balance->currency_id,
                'currency' => [
                    'id' => $balance->currency->id,
                    'code' => $balance->currency->code,
                    'symbol' => $balance->currency->symbol,
                    'name' => $balance->currency->name,
                ],
                'balance' => (float) $balance->balance,
                'is_default' => $balance->is_default,
                'note' => $balance->note,
            ], 'Баланс обновлен успешно');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при обновлении баланса: ' . $e->getMessage(), 500);
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
            $balance = ClientBalance::where('client_id', $clientId)
                ->findOrFail($id);
            
            $client = $balance->client;
            if (!$this->canPerformAction('clients', 'delete', $client)) {
                return $this->forbiddenResponse('У вас нет прав на удаление балансов этого клиента');
            }

            if ($balance->is_default) {
                $totalBalances = ClientBalance::where('client_id', $clientId)->count();
                if ($totalBalances === 1) {
                    return $this->errorResponse('Нельзя удалить единственный баланс клиента. У клиента всегда должен быть хотя бы один баланс.', 422);
                }
                return $this->errorResponse('Нельзя удалить дефолтный баланс. Сначала установите другой баланс как дефолтный.', 422);
            }

            $hasTransactions = Transaction::where('client_id', $clientId)
                ->where('currency_id', $balance->currency_id)
                ->where('is_deleted', false)
                ->exists();

            if ($hasTransactions) {
                return $this->errorResponse('Нельзя удалить баланс, если есть транзакции в этой валюте. Удалите сначала все транзакции.', 422);
            }

            DB::transaction(function () use ($balance) {
                $balance->delete();
            });

            CacheService::invalidateClientsCache();
            CacheService::invalidateClientBalanceCache($balance->client_id);

            return $this->successResponse(null, 'Баланс удален успешно');
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при удалении баланса: ' . $e->getMessage(), 500);
        }
    }
}
