<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use App\Repositories\ProjectsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionsRepository
{
    /**
     * Получить текущую компанию пользователя из заголовка запроса
     */
    private function getCurrentCompanyId()
    {
        // Получаем company_id из заголовка запроса
        return request()->header('X-Company-ID');
    }

    /**
     * Добавить фильтрацию по компании к запросу транзакций
     */
    private function addCompanyFilter($query)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            // Фильтруем транзакции по кассам текущей компании
            $query->whereHas('cashRegister', function($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        } else {
            // Если компания не выбрана, показываем только транзакции из касс без company_id
            $query->whereHas('cashRegister', function($q) {
                $q->whereNull('company_id');
            });
        }
        return $query;
    }

    public function getItemsWithPagination($userUuid, $perPage = 10, $page = 1, $cash_id = null, $date_filter_type = null, $order_id = null, $search = null, $transaction_type = null, $source = null, $project_id = null, $start_date = null, $end_date = null, $is_debt = null)
    {
        try {
            // ✅ Получаем компанию из заголовка для включения в кэш ключ
            $companyId = $this->getCurrentCompanyId() ?? 'default';

            // Создаем уникальный ключ кэша (привязываем к пользователю, компании и фильтрам)
            $searchKey = $search !== null ? md5((string)$search) : 'null';
            $cacheKey = "transactions_paginated_{$userUuid}_{$companyId}_{$perPage}_{$cash_id}_{$date_filter_type}_{$order_id}_{$searchKey}_{$transaction_type}_{$source}_{$project_id}_{$start_date}_{$end_date}_{$is_debt}";

            return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page, $cash_id, $date_filter_type, $order_id, $search, $transaction_type, $source, $project_id, $start_date, $end_date, $is_debt) {
                // Используем with() для загрузки связей вместо сложных JOIN'ов
                $query = Transaction::with([
                    'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'currency:id,name,code,symbol',
                    'cashRegister:id,name,currency_id',
                    'cashRegister.currency:id,name,code,symbol',
                    'category:id,name,type',
                    'project:id,name',
                    'user:id,name',
                    'cashTransfersFrom:id,tr_id_from',
                    'cashTransfersTo:id,tr_id_to'
                ])
                    ->addSelect([
                        'client_balance' => DB::table('clients')
                            ->select('balance')
                            ->whereColumn('clients.id', 'transactions.client_id')
                            ->limit(1)
                    ])
                    ->whereHas('cashRegister.cashRegisterUsers', function ($q) use ($userUuid) {
                        $q->where('user_id', $userUuid);
                    });

                // Фильтруем по текущей компании пользователя
                $query = $this->addCompanyFilter($query);

                $query->when($cash_id, function ($query, $cash_id) {
                        return $query->where('transactions.cash_id', $cash_id);
                    })
                    ->when($date_filter_type, function ($query, $date_filter_type) use ($start_date, $end_date) {
                        switch ($date_filter_type) {
                            case 'today':
                                return $query->whereBetween('transactions.date', [
                                    now()->startOfDay()->toDateTimeString(),
                                    now()->endOfDay()->toDateTimeString()
                                ]);
                            case 'yesterday':
                                return $query->whereBetween('transactions.date', [
                                    now()->subDay()->startOfDay()->toDateTimeString(),
                                    now()->subDay()->endOfDay()->toDateTimeString()
                                ]);
                            case 'this_week':
                                return $query->whereBetween('transactions.date', [
                                    now()->startOfWeek()->toDateTimeString(),
                                    now()->endOfWeek()->toDateTimeString()
                                ]);
                            case 'last_week':
                                return $query->whereBetween('transactions.date', [
                                    now()->subWeek()->startOfWeek()->toDateTimeString(),
                                    now()->subWeek()->endOfWeek()->toDateTimeString()
                                ]);
                            case 'this_month':
                                return $query->whereBetween('transactions.date', [
                                    now()->startOfMonth()->toDateTimeString(),
                                    now()->endOfMonth()->toDateTimeString()
                                ]);
                            case 'last_month':
                                return $query->whereBetween('transactions.date', [
                                    now()->subMonth()->startOfMonth()->toDateTimeString(),
                                    now()->subMonth()->endOfMonth()->toDateTimeString()
                                ]);
                            case 'custom':
                                if ($start_date && $end_date) {
                                    return $query->whereBetween('transactions.date', [
                                        \Carbon\Carbon::parse($start_date)->startOfDay()->toDateTimeString(),
                                        \Carbon\Carbon::parse($end_date)->endOfDay()->toDateTimeString()
                                    ]);
                                } elseif ($start_date) {
                                    return $query->where('transactions.date', '>=', \Carbon\Carbon::parse($start_date)->startOfDay()->toDateTimeString());
                                } elseif ($end_date) {
                                    return $query->where('transactions.date', '<=', \Carbon\Carbon::parse($end_date)->endOfDay()->toDateTimeString());
                                }
                                return $query;
                            default:
                                return $query;
                        }
                    })
                    ->when($order_id, function ($query, $order_id) {
                        return $query->where('source_type', 'App\\Models\\Order')
                            ->where('source_id', $order_id);
                    })
                    ->when($search, function ($query, $search) {
                        return $query->where(function ($q) use ($search) {
                            $q->where('transactions.id', 'like', "%{$search}%")
                                ->orWhere('transactions.note', 'like', "%{$search}%")
                                ->orWhereHas('client', function ($clientQuery) use ($search) {
                                    $clientQuery->where('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%")
                                        ->orWhere('contact_person', 'like', "%{$search}%");
                                });
                        });
                    })
                    ->when($transaction_type, function ($query, $transaction_type) {
                        switch ($transaction_type) {
                            case 'income':
                                return $query->where('transactions.type', 1);
                            case 'outcome':
                                return $query->where('transactions.type', 0);
                            case 'transfer':
                                return $query->where(function ($q) {
                                    $q->whereExists(function ($subQuery) {
                                        $subQuery->select(DB::raw(1))
                                            ->from('cash_transfers')
                                            ->whereColumn('cash_transfers.tr_id_from', 'transactions.id');
                                    })->orWhereExists(function ($subQuery) {
                                        $subQuery->select(DB::raw(1))
                                            ->from('cash_transfers')
                                            ->whereColumn('cash_transfers.tr_id_to', 'transactions.id');
                                    });
                                });
                            default:
                                return $query;
                        }
                    })
                    ->when($source, function ($q, $source) {
                        if (empty($source)) return $q;

                        // ✅ Фильтр по источнику (строка, не массив)
                        return $q->where(function ($subQ) use ($source) {
                            if ($source === 'sale') {
                                $subQ->where('source_type', 'App\\Models\\Sale');
                            } elseif ($source === 'order') {
                                $subQ->where('source_type', 'App\\Models\\Order');
                            } elseif ($source === 'other') {
                                $subQ->whereNull('source_type')
                                    ->orWhereNotIn('source_type', ['App\\Models\\Sale', 'App\\Models\\Order']);
                            }
                        });
                    })
                    // Фильтрация по проекту
                    ->when($project_id, function ($q, $project_id) {
                        return $q->where('transactions.project_id', $project_id);
                    })
                    // Фильтрация по кредиту
                    ->when($is_debt !== null, function ($q) use ($is_debt) {
                        if ($is_debt === 'true' || $is_debt === '1' || $is_debt === 1 || $is_debt === true) {
                            return $q->where('transactions.is_debt', true);
                        } elseif ($is_debt === 'false' || $is_debt === '0' || $is_debt === 0 || $is_debt === false) {
                            return $q->where('transactions.is_debt', false);
                        }
                        return $q;
                    })
                    // Фильтрация по доступу к проектам
                    ->where(function ($q) use ($userUuid) {
                        $q->whereNull('transactions.project_id') // Транзакции без проекта
                            ->orWhereHas('project.projectUsers', function ($subQuery) use ($userUuid) {
                                $subQuery->where('user_id', $userUuid);
                            });
                    })
                    ->orderBy('transactions.id', 'desc');

                $paginatedResults = $query->paginate($perPage, ['*'], 'page', (int)$page);

                // Принудительно загружаем связи для всех элементов пагинации
                $paginatedResults->getCollection()->load([
                    'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'currency:id,name,code,symbol',
                    'cashRegister:id,name,currency_id',
                    'cashRegister.currency:id,name,code,symbol',
                    'category:id,name,type',
                    'project:id,name',
                    'user:id,name',
                    'cashTransfersFrom:id,tr_id_from',
                    'cashTransfersTo:id,tr_id_to'
                ]);

                // Подсчитываем ТЕКУЩИЕ непогашенные долги клиентов (из clients.balance)
                // Это правильно, потому что долги могут быть погашены обычными транзакциями
                $debtStats = DB::table('clients')
                    ->select([
                        DB::raw('SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) as positive'),
                        DB::raw('SUM(CASE WHEN balance < 0 THEN ABS(balance) ELSE 0 END) as negative'),
                    ])
                    ->first();

                $totalDebtPositive = $debtStats->positive ?? 0; // Должны нам
                $totalDebtNegative = $debtStats->negative ?? 0; // Мы должны

                // Преобразуем данные в тот же формат, что и в методе getItems
                $paginatedResults->getCollection()->transform(function ($transaction) {
                    return (object) [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'is_transfer' => $this->isTransfer($transaction),
                        'is_sale' => $this->isSale($transaction),
                        'is_receipt' => $this->isReceipt($transaction),
                        'is_debt' => $transaction->is_debt,
                        'cash_id' => $transaction->cash_id,
                        'cash_name' => $transaction->cashRegister?->name,
                        'cash_amount' => $transaction->amount,
                        'cash_currency_id' => $transaction->cashRegister?->currency?->id,
                        'cash_currency_name' => $transaction->cashRegister?->currency?->name,
                        'cash_currency_code' => $transaction->cashRegister?->currency?->code,
                        'cash_currency_symbol' => $transaction->cashRegister?->currency?->symbol,
                        'orig_amount' => $transaction->orig_amount,
                        'orig_currency_id' => $transaction->currency?->id,
                        'orig_currency_name' => $transaction->currency?->name,
                        'orig_currency_code' => $transaction->currency?->code,
                        'orig_currency_symbol' => $transaction->currency?->symbol,
                        'user_id' => $transaction->user_id,
                        'user_name' => $transaction->user?->name,
                        'category_id' => $transaction->category_id,
                        'category_name' => $transaction->category?->name,
                        'category_type' => $transaction->category?->type,
                        'project_id' => $transaction->project_id,
                        'project_name' => $transaction->project?->name,
                        'client_id' => $transaction->client_id,
                        'client' => $transaction->client ? [
                            'id' => $transaction->client->id,
                            'first_name' => $transaction->client->first_name,
                            'last_name' => $transaction->client->last_name,
                            'contact_person' => $transaction->client->contact_person,
                            'client_type' => $transaction->client->client_type,
                            'is_supplier' => $transaction->client->is_supplier,
                            'is_conflict' => $transaction->client->is_conflict,
                            'address' => $transaction->client->address,
                            'note' => $transaction->client->note,
                            'status' => $transaction->client->status,
                            'discount_type' => $transaction->client->discount_type,
                            'discount' => $transaction->client->discount,
                            'created_at' => $transaction->client->created_at,
                            'updated_at' => $transaction->client->updated_at,
                            'phones' => $transaction->client->phones ? $transaction->client->phones->toArray() : [],
                            'emails' => $transaction->client->emails ? $transaction->client->emails->toArray() : [],
                            'balance' => $transaction->client_balance ?? 0,
                        ] : null,
                        'note' => $transaction->note,
                        'date' => $transaction->date,
                        'created_at' => $transaction->created_at,
                        'updated_at' => $transaction->updated_at,
                    ];
                });

                // Добавляем статистику по долгам в ответ
                $paginatedResults->total_debt_positive = $totalDebtPositive;
                $paginatedResults->total_debt_negative = $totalDebtNegative;
                $paginatedResults->total_debt_balance = $totalDebtPositive - $totalDebtNegative;

                return $paginatedResults;
            }, (int)$page);
        } catch (\Exception $e) {
            throw $e;
        }
    }



    public function createItem($data, $return_id = false, bool $skipClientUpdate = false)
    {
        $cashRegister = CashRegister::find($data['cash_id']);
        $originalAmount = $data['orig_amount'];
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');

        Log::info('TransactionsRepository::createItem - START', [
            'data' => $data,
            'skipClientUpdate' => $skipClientUpdate,
            'timestamp' => now()
        ]);

        $currencyIds = array_unique([
            $data['currency_id'],
            $cashRegister->currency_id,
            $defaultCurrencyId,
        ]);

        $currencies = Currency::whereIn('id', $currencyIds)->get()->keyBy('id');

        $fromCurrency = $currencies[$data['currency_id']];
        $toCurrency = $currencies[$cashRegister->currency_id];
        $defaultCurrency = $currencies[$defaultCurrencyId];


        if ($fromCurrency->id === $toCurrency->id) {
            $convertedAmount = $originalAmount;
        } else {
            $convertedAmount = CurrencyConverter::convert($originalAmount, $fromCurrency, $toCurrency);
        }

        // Применяем округление если оно включено в кассе
        $convertedAmount = $cashRegister->roundAmount($convertedAmount);

        if ($fromCurrency->id !== $defaultCurrency->id) {
            $convertedAmountDefault = CurrencyConverter::convert($originalAmount, $fromCurrency, $defaultCurrency);
        } else {
            $convertedAmountDefault = $originalAmount;
        }

        Log::info('TransactionsRepository::createItem - Amounts calculated', [
            'originalAmount' => $originalAmount,
            'convertedAmount' => $convertedAmount,
            'convertedAmountDefault' => $convertedAmountDefault,
            'fromCurrency' => $fromCurrency->code,
            'defaultCurrency' => $defaultCurrency->code,
        ]);

        DB::beginTransaction();

        try {
            $transaction = new Transaction();
            $transaction->type = $data['type'];
            $transaction->user_id = $data['user_id'];
            $transaction->orig_amount = $originalAmount;
            $transaction->amount = $convertedAmount;
            $transaction->currency_id = $data['currency_id'];
            $transaction->cash_id = $cashRegister->id;
            $transaction->category_id = $data['category_id'];
            $transaction->project_id = $data['project_id'];
            $transaction->client_id = $data['client_id'];
            $transaction->note = $data['note'];
            $transaction->date = $data['date'];
            $transaction->is_debt = $data['is_debt'] ?? false;
            $transaction->source_type = $data['source_type'] ?? null;
            $transaction->source_id = $data['source_id'] ?? null;
            // Удалено поле order_id - теперь используется связующая таблица

            // ВАЖНО: Для обычных транзакций (is_debt=false) позволяем Transaction::created обновить баланс
            // Для долговых операций (is_debt=true) пропускаем, и обновляем вручную ниже
            $shouldSkipClientBalanceUpdate = ($data['is_debt'] ?? false);
            $transaction->setSkipClientBalanceUpdate($shouldSkipClientBalanceUpdate);
            $transaction->setSkipCashBalanceUpdate(true); // Касса обновляется в репозитории
            $transaction->save();

            Log::info('TransactionsRepository::createItem - Transaction saved', [
                'transaction_id' => $transaction->id,
                'client_id' => $transaction->client_id,
                'is_debt' => $transaction->is_debt,
                'type' => $transaction->type,
                'skipClientBalanceUpdate' => $shouldSkipClientBalanceUpdate
            ]);

            // Связь с заказом теперь устанавливается через morphable поля source_type и source_id

            // Обновляем кассу ТОЛЬКО для обычных транзакций (is_debt=false)
            // Долговые транзакции (is_debt=true) НЕ должны влиять на кассу
            if (!($data['is_debt'] ?? false) && $cashRegister) {
                if ((int)$data['type'] === 1) {
                    $cashRegister->balance += $convertedAmount; // доход
                } else {
                    $cashRegister->balance -= $convertedAmount; // расход
                }
                $cashRegister->save();

                Log::info('TransactionsRepository::createItem - Cash balance updated', [
                    'cashRegister_id' => $cashRegister->id,
                    'new_balance' => $cashRegister->balance,
                    'is_debt' => false
                ]);
            } else {
                Log::info('TransactionsRepository::createItem - Cash balance NOT updated (debt transaction)', [
                    'cashRegister_id' => $cashRegister->id ?? null,
                    'is_debt' => $data['is_debt'] ?? false
                ]);
            }

            // Обновляем баланс клиента ТОЛЬКО если это долговая операция
            Log::info('TransactionsRepository::createItem - Before client balance update', [
                'client_id' => $data['client_id'],
                'is_debt' => $data['is_debt'] ?? false,
                'skipClientUpdate' => $skipClientUpdate,
                'convertedAmountDefault' => $convertedAmountDefault,
                'type' => $data['type'],
                'note' => 'Regular transactions (is_debt=false) are updated via Transaction::created'
            ]);

            // ДЛЯ ДОЛГОВЫХ ОПЕРАЦИЙ: обновляем баланс клиента вручную через DB
            // ДЛЯ ОБЫЧНЫХ ТРАНЗАКЦИЙ: баланс обновляется автоматически через Transaction::created hook
            if (! $skipClientUpdate && ! empty($data['client_id']) && ($data['is_debt'] ?? false)) {
                Log::info('TransactionsRepository::createItem - UPDATING CLIENT BALANCE FOR DEBT TRANSACTION', [
                    'client_id' => $data['client_id'],
                    'operation_type' => $data['type'] === 1 ? 'income (ADD - client buys on credit)' : 'outcome (SUBTRACT - client pays)',
                    'amount' => $convertedAmountDefault,
                    'is_debt' => true
                ]);

                // ЛОГИКА ДЛЯ ДОЛГОВЫХ ОПЕРАЦИЙ (is_debt=true):
                // type=1 (доход): Клиент купил В ДОЛГ → должен НАМ → баланс УВЕЛИЧИВАЕТСЯ
                // type=0 (расход): Клиент ПЛАТИТ ДОЛГ → баланс УМЕНЬШАЕТСЯ
                if ($data['type'] === 1) {
                    DB::table('clients')->where('id', $data['client_id'])->update([
                        'balance' => DB::raw('balance + ' . ($convertedAmountDefault + 0))
                    ]);
                    Log::info('TransactionsRepository::createItem - DEBT: CLIENT BALANCE DECREASED (Income)', [
                        'client_id' => $data['client_id'],
                        'amount_added' => $convertedAmountDefault
                    ]);
                } else {
                    DB::table('clients')->where('id', $data['client_id'])->update([
                        'balance' => DB::raw('balance - ' . ($convertedAmountDefault + 0))
                    ]);
                    Log::info('TransactionsRepository::createItem - DEBT: CLIENT BALANCE DECREASED (Payment)', [
                        'client_id' => $data['client_id'],
                        'amount_subtracted' => $convertedAmountDefault
                    ]);
                }

                // Получаем обновленный баланс для проверки
                $updatedClient = DB::table('clients')->where('id', $data['client_id'])->first();
                Log::info('TransactionsRepository::createItem - UPDATED CLIENT BALANCE VERIFICATION', [
                    'client_id' => $data['client_id'],
                    'new_balance_from_db' => $updatedClient->balance ?? 'NOT FOUND'
                ]);

                // Инвалидируем кэш клиентов и проектов после обновления баланса
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($data['client_id']);
                CacheService::invalidateProjectsCache();
            } else {
                Log::info('TransactionsRepository::createItem - CLIENT BALANCE UPDATE (Regular Transaction)', [
                    'reason' => 'Will be updated via Transaction::created model event',
                    'is_debt' => $data['is_debt'] ?? false,
                    'client_id' => $data['client_id'] ?? null
                ]);
            }

            DB::commit();

            // Инвалидируем кэш транзакций и баланса клиента
            $this->invalidateTransactionsCache();
            if (!empty($data['client_id'])) {
                $this->invalidateClientBalanceCache($data['client_id']);
            }

            // Инвалидируем кэш касс, так как баланс изменился
            CacheService::invalidateCashRegistersCache();

            // Инвалидируем кэш проекта если транзакция связана с проектом
            if (!empty($data['project_id'])) {
                $projectsRepository = new \App\Repositories\ProjectsRepository();
                $projectsRepository->invalidateProjectCache($data['project_id']);
            }

            Log::info('TransactionsRepository::createItem - SUCCESS', [
                'transaction_id' => $transaction->id,
                'client_id' => $transaction->client_id,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('TransactionsRepository::createItem - ERROR', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        return $return_id ? $transaction->id : true;
    }

    public function updateItem($id, $data)
    {
        $transaction = Transaction::find($id);
        if (!$transaction) {
            return false;
        }

        // Если изменяется сумма, валюта, статус долга или клиент, нужно пересчитать баланс
        $needsBalanceUpdate = isset($data['orig_amount']) || isset($data['currency_id']) || isset($data['is_debt']) || isset($data['client_id']);

        // Логируем для отладки
        Log::info('TransactionsRepository::updateItem', [
            'transaction_id' => $id,
            'data' => $data,
            'needsBalanceUpdate' => $needsBalanceUpdate,
            'old_client_id' => $transaction->client_id,
            'old_is_debt' => $transaction->is_debt,
            'old_amount' => $transaction->amount
        ]);

        if ($needsBalanceUpdate) {
            return $this->updateItemWithBalanceRecalculation($id, $data);
        }

        // Обычное обновление без изменения суммы
        $transaction->client_id = $data['client_id'];
        $transaction->category_id = $data['category_id'];
        $transaction->project_id = $data['project_id'];
        $transaction->date = $data['date'];
        $transaction->note = $data['note'];

        // Обновляем is_debt если передано
        if (isset($data['is_debt'])) {
            $transaction->is_debt = $data['is_debt'];
        }

        $transaction->save();

        // Инвалидируем кэш транзакций и баланса клиента
        $this->invalidateTransactionsCache();
        if ($transaction->client_id) {
            $this->invalidateClientBalanceCache($transaction->client_id);
        }

        // Инвалидируем кэш касс
        CacheService::invalidateCashRegistersCache();

        // Инвалидируем кэш проекта если транзакция связана с проектом
        if ($transaction->project_id) {
            $projectsRepository = new ProjectsRepository();
            $projectsRepository->invalidateProjectCache($transaction->project_id);
        }

        return true;
    }

    private function updateItemWithBalanceRecalculation($id, $data)
    {
        $transaction = Transaction::find($id);
        if (!$transaction) {
            return false;
        }

        // Логируем для отладки
        Log::info('TransactionsRepository::updateItemWithBalanceRecalculation', [
            'transaction_id' => $id,
            'data' => $data,
            'old_client_id' => $transaction->client_id,
            'old_is_debt' => $transaction->is_debt,
            'old_amount' => $transaction->amount
        ]);

        DB::beginTransaction();

        try {
            $cashRegister = CashRegister::find($transaction->cash_id);
            if (!$cashRegister) {
                throw new \Exception('Касса не найдена');
            }

            // Сохраняем старые значения для отката баланса
            $oldAmount = $transaction->amount;
            $oldOrigAmount = $transaction->orig_amount;
            $oldCurrencyId = $transaction->currency_id;
            $oldIsDebt = $transaction->is_debt;
            $oldClientId = $transaction->client_id;
            $oldSourceType = $transaction->source_type;
            $oldType = $transaction->type; // Сохраняем старый type для правильного отката

            // Откатываем старый баланс кассы только если транзакция не была долговой
            if (!$oldIsDebt) {
                if ($oldType == 1) {
                    $cashRegister->balance -= $oldAmount;
                } else {
                    $cashRegister->balance += $oldAmount;
                }
            }

            // Обновляем поля транзакции
            $transaction->client_id = $data['client_id'];
            $transaction->category_id = $data['category_id'];
            $transaction->project_id = $data['project_id'];
            $transaction->date = $data['date'];
            $transaction->note = $data['note'];

            // Обновляем сумму и валюту если переданы
            if (isset($data['orig_amount'])) {
                $transaction->orig_amount = $data['orig_amount'];
            }
            if (isset($data['currency_id'])) {
                $transaction->currency_id = $data['currency_id'];
            }
            if (isset($data['is_debt'])) {
                $transaction->is_debt = $data['is_debt'];
            }

            // Пересчитываем конвертированную сумму
            $newOrigAmount = $transaction->orig_amount;
            $newCurrencyId = $transaction->currency_id;

            // Получаем валюты
            $defaultCurrencyId = Currency::where('is_default', true)->value('id');
            $currencyIds = array_unique([
                $newCurrencyId,
                $cashRegister->currency_id,
                $defaultCurrencyId,
            ]);

            $currencies = Currency::whereIn('id', $currencyIds)->get()->keyBy('id');
            $fromCurrency = $currencies[$newCurrencyId];
            $toCurrency = $currencies[$cashRegister->currency_id];

            // Конвертируем новую сумму
            if ($fromCurrency->id === $toCurrency->id) {
                $newConvertedAmount = $newOrigAmount;
            } else {
                $newConvertedAmount = CurrencyConverter::convert($newOrigAmount, $fromCurrency, $toCurrency);
            }

            // Применяем округление если оно включено в кассе
            $newConvertedAmount = $cashRegister->roundAmount($newConvertedAmount);

            // Обновляем конвертированную сумму
            $transaction->amount = $newConvertedAmount;

            // ВАЖНО: Для обычных транзакций (is_debt=false) позволяем Transaction::updated обновить баланс
            // Для долговых операций (is_debt=true) пропускаем, и обновляем вручную ниже
            $shouldSkipClientBalanceUpdate = $transaction->is_debt;
            $transaction->setSkipClientBalanceUpdate($shouldSkipClientBalanceUpdate);

            Log::info('TransactionsRepository::updateItemWithBalanceRecalculation - Before transaction save', [
                'transaction_id' => $id,
                'skipClientBalanceUpdate' => $shouldSkipClientBalanceUpdate,
                'is_debt' => $transaction->is_debt,
                'old_amount' => $oldAmount,
                'new_amount' => $newConvertedAmount
            ]);

            $transaction->save();

            Log::info('TransactionsRepository::updateItemWithBalanceRecalculation - Transaction saved', [
                'transaction_id' => $transaction->id,
                'client_id' => $transaction->client_id,
                'is_debt' => $transaction->is_debt,
                'type' => $transaction->type,
                'skipClientBalanceUpdate' => $shouldSkipClientBalanceUpdate
            ]);

            // Применяем новый баланс кассы только если транзакция не стала долговой
            if (!$transaction->is_debt) {
                if ($transaction->type == 1) {
                    $cashRegister->balance += $newConvertedAmount;
                } else {
                    $cashRegister->balance -= $newConvertedAmount;
                }
            }

            // Сохраняем кассу в любом случае, если был откат или применение
            $cashRegister->save();

            // Обновляем баланс клиента с учетом флага is_debt и изменения клиента

            Log::info('Updating client balance', [
                'transaction_id' => $id,
                'old_client_id' => $oldClientId,
                'new_client_id' => $transaction->client_id,
                'old_is_debt' => $oldIsDebt,
                'new_is_debt' => $transaction->is_debt,
                'client_changed' => $oldClientId !== $transaction->client_id
            ]);

            // Если клиент изменился, нужно откатить баланс у старого клиента
            $oldIsRegularTransaction = empty($oldSourceType);
            if ($oldClientId && $oldClientId !== $transaction->client_id && ($oldIsDebt || $oldIsRegularTransaction)) {
                if ($oldCurrencyId !== $defaultCurrencyId) {
                    $oldConvertedAmountDefault = CurrencyConverter::convert($oldOrigAmount, $currencies[$oldCurrencyId], $currencies[$defaultCurrencyId]);
                } else {
                    $oldConvertedAmountDefault = $oldOrigAmount;
                }

                // Откат старого баланса: делаем обратную операцию через DB::table
                if ($oldIsDebt) {
                    // Долговая операция: откатываем как было при создании
                    if ($oldType == 1) {
                        // Было type=1 (доход): было +=, откатываем -=
                        DB::table('clients')->where('id', $oldClientId)->update([
                            'balance' => DB::raw('balance - ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    } else {
                        // Было type=0 (расход): было -=, откатываем +=
                        DB::table('clients')->where('id', $oldClientId)->update([
                            'balance' => DB::raw('balance + ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    }
                } else {
                    // Обычная транзакция: откатываем как было при создании
                    if ($oldType == 1) {
                        // Было type=1 (доход): было -=, откатываем +=
                        DB::table('clients')->where('id', $oldClientId)->update([
                            'balance' => DB::raw('balance + ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    } else {
                        // Было type=0 (расход): было +=, откатываем -=
                        DB::table('clients')->where('id', $oldClientId)->update([
                            'balance' => DB::raw('balance - ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    }
                }

                CacheService::invalidateClientBalanceCache($oldClientId);
            }

            // Обновляем баланс нового клиента
            if ($transaction->client_id) {
                // Работаем с колонкой clients.balance

                Log::info('Starting client balance update', [
                    'transaction_id' => $id,
                    'client_id' => $transaction->client_id,
                    'source_type' => $transaction->source_type,
                    'is_debt' => $transaction->is_debt,
                    'type' => $transaction->type,
                    'note' => 'Regular transactions will be handled by Transaction::updated hook'
                ]);

                // ЛОГИКА:
                // Для ОБЫЧНЫХ транзакций (is_debt=false) - Transaction::updated hook уже сработает
                // Для ДОЛГОВЫХ операций (is_debt=true) - обновляем вручную

                // Если клиент изменился и была долговая операция - откатываем баланс старого клиента
                $oldIsRegularTransaction = empty($oldSourceType);
                if ($oldClientId && $oldClientId !== $transaction->client_id && ($oldIsDebt || $oldIsRegularTransaction)) {
                    if ($oldCurrencyId !== $defaultCurrencyId) {
                        $oldConvertedAmountDefault = CurrencyConverter::convert($oldOrigAmount, $currencies[$oldCurrencyId], $currencies[$defaultCurrencyId]);
                    } else {
                        $oldConvertedAmountDefault = $oldOrigAmount;
                    }

                    // Откат для старого клиента - используем $oldType для правильного отката
                    if ($oldType == 1) {
                        DB::table('clients')->where('id', $oldClientId)->update([
                            'balance' => DB::raw('balance + ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    } else {
                        DB::table('clients')->where('id', $oldClientId)->update([
                            'balance' => DB::raw('balance - ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    }

                    CacheService::invalidateClientBalanceCache($oldClientId);

                    Log::info('Old client balance rolled back', [
                        'transaction_id' => $id,
                        'old_client_id' => $oldClientId,
                        'operation' => $oldType == 1 ? 'ADD (reverse of income)' : 'SUBTRACT (reverse of expense)'
                    ]);
                }

                // Применяем новый баланс клиента ТОЛЬКО если это долговая операция
                // Для обычных транзакций это сделает Transaction::updated hook
                if ($transaction->is_debt) {
                    Log::info('Manual balance update for DEBT transaction', [
                        'transaction_id' => $id,
                        'client_id' => $transaction->client_id,
                        'is_debt' => true,
                        'type' => $transaction->type
                    ]);

                    // Откатываем старый баланс если транзакция осталась для этого же клиента
                    $if_need_rollback = $oldClientId === $transaction->client_id && ($oldIsDebt || empty($oldSourceType));
                    if ($if_need_rollback) {
                        if ($oldCurrencyId !== $defaultCurrencyId) {
                            $oldConvertedAmountDefault = CurrencyConverter::convert($oldOrigAmount, $currencies[$oldCurrencyId], $currencies[$defaultCurrencyId]);
                        } else {
                            $oldConvertedAmountDefault = $oldOrigAmount;
                        }

                        // Откат старого баланса: делаем обратную операцию для долговых транзакций
                        // type=1 при создании: balance += amount → при откате: balance -= amount
                        // type=0 при создании: balance -= amount → при откате: balance += amount
                        if ($transaction->type == 1) {
                            DB::table('clients')->where('id', $transaction->client_id)->update([
                                'balance' => DB::raw('balance - ' . ($oldConvertedAmountDefault + 0))
                            ]);
                        } else {
                            DB::table('clients')->where('id', $transaction->client_id)->update([
                                'balance' => DB::raw('balance + ' . ($oldConvertedAmountDefault + 0))
                            ]);
                        }

                        Log::info('Rolled back old balance for same client', [
                            'transaction_id' => $id,
                            'client_id' => $transaction->client_id
                        ]);
                    }

                    // Применяем новый баланс
                    if ($newCurrencyId !== $defaultCurrencyId) {
                        $newConvertedAmountDefault = CurrencyConverter::convert($newOrigAmount, $fromCurrency, $currencies[$defaultCurrencyId]);
                    } else {
                        $newConvertedAmountDefault = $newOrigAmount;
                    }

                    // Применение нового баланса для долговых операций:
                    // type=1 (доход): клиент купил в долг → баланс +=
                    // type=0 (расход): клиент платит → баланс -=
                    if ($transaction->type == 1) {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance + ' . ($newConvertedAmountDefault + 0))
                        ]);
                        Log::info('Debt transaction balance: ADDED (credit sale)', [
                            'transaction_id' => $id,
                            'client_id' => $transaction->client_id,
                            'amount' => $newConvertedAmountDefault
                        ]);
                    } else {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance - ' . ($newConvertedAmountDefault + 0))
                        ]);
                        Log::info('Debt transaction balance: SUBTRACTED (payment)', [
                            'transaction_id' => $id,
                            'client_id' => $transaction->client_id,
                            'amount' => $newConvertedAmountDefault
                        ]);
                    }

                    // Инвалидируем кэш для долговых операций
                    CacheService::invalidateClientsCache();
                    CacheService::invalidateClientBalanceCache($transaction->client_id);
                    CacheService::invalidateProjectsCache();
                } else {
                    Log::info('Balance will be auto-updated via Transaction::updated hook for REGULAR transaction', [
                        'transaction_id' => $id,
                        'client_id' => $transaction->client_id,
                        'is_debt' => false
                    ]);
                }
            }

            DB::commit();

            // Инвалидируем кэш транзакций и баланса клиента
            $this->invalidateTransactionsCache();
            if ($transaction->client_id) {
                $this->invalidateClientBalanceCache($transaction->client_id);
            }

            // Инвалидируем кэш касс, так как баланс изменился
            CacheService::invalidateCashRegistersCache();

            // Инвалидируем кэш проекта если транзакция связана с проектом
            if ($transaction->project_id) {
                $projectsRepository = new ProjectsRepository();
                $projectsRepository->invalidateProjectCache($transaction->project_id);
            }

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function deleteItem(int $id, bool $skipClientUpdate = false): bool
    {
        $transaction = Transaction::find($id);
        if (!$transaction) {
            return false;
        }

        DB::beginTransaction();

        try {
            $cashRegister = CashRegister::find($transaction->cash_id);
            if (!$cashRegister) {
                throw new \Exception('Касса не найдена');
            }

            // Получаем валюту по умолчанию и исходную валюту транзакции
            $defaultCurrencyId = Currency::where('is_default', true)->value('id');
            $currencyIds = array_unique([
                $transaction->currency_id,
                $defaultCurrencyId,
            ]);

            $currencies = Currency::whereIn('id', $currencyIds)->get()->keyBy('id');
            $fromCurrency = $currencies[$transaction->currency_id];
            $defaultCurrency = $currencies[$defaultCurrencyId];

            // Для кассы используем уже сохраненную сумму в валюте кассы без повторной конвертации
            $convertedAmount = $transaction->amount;

            // Для клиента конвертируем исходную сумму в базовую валюту
            if ($fromCurrency->id !== $defaultCurrency->id) {
                $convertedAmountDefault = CurrencyConverter::convert($transaction->orig_amount, $fromCurrency, $defaultCurrency);
            } else {
                $convertedAmountDefault = $transaction->orig_amount;
            }

            // Корректируем баланс кассы только если это не долговая операция
            if (!$transaction->is_debt) {
                if ($transaction->type == 1) {
                    // Доход: при удалении уменьшаем баланс кассы
                    $cashRegister->balance -= $convertedAmount;
                } else {
                    // Расход: при удалении увеличиваем баланс кассы
                    $cashRegister->balance += $convertedAmount;
                }
                $cashRegister->save();
            }

            // Связи с заказами теперь автоматически удаляются через morphable связи

            // ВАЖНО: Для обычных транзакций (is_debt=false) позволяем Transaction::deleted обновить баланс
            // Для долговых операций (is_debt=true) пропускаем, и обновляем вручную ниже
            $shouldSkipClientBalanceUpdate = $transaction->is_debt;
            $transaction->setSkipClientBalanceUpdate($shouldSkipClientBalanceUpdate);
            $transaction->delete();

            Log::info('TransactionsRepository::deleteItem - Transaction deleted', [
                'transaction_id' => $transaction->id,
                'client_id' => $transaction->client_id,
                'is_debt' => $transaction->is_debt,
                'type' => $transaction->type,
                'skipClientBalanceUpdate' => $shouldSkipClientBalanceUpdate
            ]);

            // Обновляем баланс клиента ТОЛЬКО если это была долговая операция
            if (! $skipClientUpdate && $transaction->client_id && $transaction->is_debt) {
                Log::info('TransactionsRepository::deleteItem - UPDATING CLIENT BALANCE FOR DEBT TRANSACTION', [
                    'client_id' => $transaction->client_id,
                    'operation_type' => $transaction->type === 1 ? 'income (SUBTRACT back - reverse of ADD)' : 'outcome (ADD back - reverse of SUBTRACT)',
                    'amount' => $convertedAmountDefault,
                    'is_debt' => true
                ]);

                // При удалении долговой операции откатываем её
                // type=1 (доход): было +=, откатываем -=
                // type=0 (расход): было -=, откатываем +=
                if ($transaction->type == 1) {
                    DB::table('clients')->where('id', $transaction->client_id)->update([
                        'balance' => DB::raw('balance - ' . ($convertedAmountDefault + 0))
                    ]);
                    Log::info('TransactionsRepository::deleteItem - DEBT: CLIENT BALANCE DECREASED (reverse of credit sale)', [
                        'client_id' => $transaction->client_id,
                        'amount_subtracted' => $convertedAmountDefault
                    ]);
                } else {
                    DB::table('clients')->where('id', $transaction->client_id)->update([
                        'balance' => DB::raw('balance + ' . ($convertedAmountDefault + 0))
                    ]);
                    Log::info('TransactionsRepository::deleteItem - DEBT: CLIENT BALANCE INCREASED (reverse of payment)', [
                        'client_id' => $transaction->client_id,
                        'amount_added' => $convertedAmountDefault
                    ]);
                }

                // Инвалидируем кэш клиентов и проектов после обновления баланса
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($transaction->client_id);
                CacheService::invalidateProjectsCache();
            } else {
                Log::info('TransactionsRepository::deleteItem - CLIENT BALANCE UPDATE (Regular Transaction)', [
                    'reason' => 'Will be updated via Transaction::deleted model event',
                    'is_debt' => $transaction->is_debt,
                    'client_id' => $transaction->client_id
                ]);
            }

            DB::commit();

            // Инвалидируем кэш транзакций и баланса клиента
            $this->invalidateTransactionsCache();
            if ($transaction->client_id) {
                $this->invalidateClientBalanceCache($transaction->client_id);
            }

            // Инвалидируем кэш касс, так как баланс изменился
            CacheService::invalidateCashRegistersCache();

            // Инвалидируем кэш проекта если транзакция связана с проектом
            if ($transaction->project_id) {
                $projectsRepository = new ProjectsRepository();
                $projectsRepository->invalidateProjectCache($transaction->project_id);
            }

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getTotalByOrderId($userId, $orderId)
    {
        return Transaction::where('transactions.user_id', $userId)
            ->where('source_type', 'App\Models\Order')
            ->where('source_id', $orderId)
            ->sum('orig_amount');
    }

    private function isTransfer($transaction)
    {
        // Проверяем сначала загруженные связи, затем делаем запрос если нужно
        if ($transaction->relationLoaded('cashTransfersFrom') && $transaction->relationLoaded('cashTransfersTo')) {
            return $transaction->cashTransfersFrom->count() > 0 || $transaction->cashTransfersTo->count() > 0;
        }

        return $transaction->cashTransfersFrom()->exists() || $transaction->cashTransfersTo()->exists();
    }

    private function isSale($transaction)
    {
        return $transaction->source_type === 'App\Models\Sale';
    }

    private function isReceipt($transaction)
    {
        return $transaction->source_type === 'App\Models\WarehouseReceipt';
    }

    public function userHasPermissionToCashRegister($userUuid, $cashRegisterId)
    {
        return CashRegister::query()
            ->where('cash_registers.id', $cashRegisterId)
            ->whereExists(function ($subQuery) use ($userUuid) {
                $subQuery->select(DB::raw(1))
                    ->from('cash_register_users')
                    ->whereColumn('cash_register_users.cash_register_id', 'cash_registers.id')
                    ->where('cash_register_users.user_id', $userUuid);
            })->exists();
    }

    /**
     * Инвалидация кэша транзакций
     */
    public function invalidateTransactionsCache()
    {
        // Делегируем централизованной службе кэша
        CacheService::invalidateTransactionsCache();
    }

    public function getItemById($id)
    {
        $items = $this->getItems([$id]);
        return $items->first();
    }


    private function getItems(array $ids = [])
    {
        $query = Transaction::query();
        $query->leftJoin('users as users', 'transactions.user_id', '=', 'users.id');
        $query->leftJoin('currencies as currencies', 'transactions.currency_id', '=', 'currencies.id');
        $query->join('cash_registers as cash_registers', 'transactions.cash_id', '=', 'cash_registers.id');
        $query->join('currencies as cash_register_currencies', 'cash_registers.currency_id', '=', 'cash_register_currencies.id');
        $query->leftJoin('transaction_categories as transaction_categories', 'transactions.category_id', '=', 'transaction_categories.id');
        $query->leftJoin('projects as projects', 'transactions.project_id', '=', 'projects.id');
        $query->leftJoin('cash_transfers as cash_transfers_from', 'transactions.id', '=', 'cash_transfers_from.tr_id_from');
        $query->leftJoin('cash_transfers as cash_transfers_to', 'transactions.id', '=', 'cash_transfers_to.tr_id_to');

        $query->whereIn('transactions.id', $ids);
        $query->select(
            'transactions.id as id',
            'transactions.type as type',
            'transactions.is_debt as is_debt',
            DB::raw('CASE
            WHEN cash_transfers_from.tr_id_from IS NOT NULL
              OR cash_transfers_to.tr_id_to IS NOT NULL
                THEN true
                ELSE false
            END as is_transfer'),
            'transactions.cash_id as cash_id',
            'cash_registers.name as cash_name',
            'transactions.amount as cash_amount',
            'cash_register_currencies.id as cash_currency_id',
            'cash_register_currencies.name as cash_currency_name',
            'cash_register_currencies.code as cash_currency_code',
            'cash_register_currencies.symbol as cash_currency_symbol',
            'transactions.orig_amount as orig_amount',
            'currencies.id as orig_currency_id',
            'currencies.name as orig_currency_name',
            'currencies.code as orig_currency_code',
            'currencies.symbol as orig_currency_symbol',
            'transactions.user_id as user_id',
            'users.name as user_name',
            'transactions.category_id as category_id',
            'transaction_categories.name as category_name',
            'transaction_categories.type as category_type',
            'transactions.project_id as project_id',
            'projects.name as project_name',
            'transactions.client_id as client_id',
            'transactions.note as note',
            'transactions.date as date',
            // Удалено поле order_id - теперь используется связующая таблица
            'transactions.updated_at as updated_at',
            'transactions.created_at as created_at',
        );
        $items = $query->get();

        // Загружаем клиентов с помощью with() для лучшей производительности
        $clientIds = $items->pluck('client_id')->filter()->unique()->toArray();
        $clients = collect();

        if (!empty($clientIds)) {
            $clients = Client::whereIn('id', $clientIds)
                ->with(['phones', 'emails'])
                ->select([
                    'id',
                    'first_name',
                    'last_name',
                    'contact_person',
                    'client_type',
                    'is_supplier',
                    'is_conflict',
                    'address',
                    'note',
                    'status',
                    'discount_type',
                    'discount',
                    'created_at',
                    'updated_at',
                    'clients.balance as balance'
                ])
                ->get()
                ->keyBy('id');
        }

        foreach ($items as $item) {
            $item = (object) $item->toArray();
            $item->client = $clients->get($item->client_id);
        }
        return $items;
    }


    // Инвалидация кэша баланса клиента
    private function invalidateClientBalanceCache($clientId)
    {
        $clientsRepository = new ClientsRepository();
        $clientsRepository->invalidateClientBalanceCache($clientId);
    }
}
