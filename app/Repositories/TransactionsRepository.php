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

class TransactionsRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 10, $page = 1, $cash_id = null, $date_filter_type = null, $order_id = null, $search = null, $transaction_type = null, $source = null, $project_id = null, $start_date = null, $end_date = null, $is_debt = null)
    {
        try {
            // Создаем уникальный ключ кэша (привязываем к пользователю и фильтрам/кассе, без company_id)
            $searchKey = $search !== null ? md5((string)$search) : 'null';
            $cacheKey = "transactions_paginated_{$userUuid}_{$perPage}_{$cash_id}_{$date_filter_type}_{$order_id}_{$searchKey}_{$transaction_type}_{$source}_{$project_id}_{$start_date}_{$end_date}_{$is_debt}";

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
                    })
                    ->when($cash_id, function ($query, $cash_id) {
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
                    // Фильтрация по долгу
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

                // Подсчитываем ТЕКУЩИЕ непогашенные долги клиентов (из client_balances)
                // Это правильно, потому что долги могут быть погашены обычными транзакциями
                $debtStats = DB::table('client_balances')
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
            $transaction->setSkipClientBalanceUpdate(true);
            $transaction->setSkipCashBalanceUpdate(true); // Касса обновляется в репозитории
            $transaction->save();

            // Связь с заказом теперь устанавливается через morphable поля source_type и source_id

            // Обновляем кассу:
            // 1. Обычные транзакции (is_debt=false) - касса меняется
            // 2. СПЕЦИАЛЬНО для оплат заказов (source_type=Order, type=0, is_debt=true) - касса ТОЖЕ меняется!
            $isOrderPayment = ($data['is_debt'] ?? false) &&
                              !empty($data['source_type']) &&
                              $data['source_type'] === 'App\Models\Order' &&
                              $data['type'] === 0;

            if ((!($data['is_debt'] ?? false) || $isOrderPayment) && $cashRegister) {
                if ((int)$data['type'] === 1) {
                    $cashRegister->balance += $convertedAmount;
                } else {
                    // Для type=0:
                    // - Обычная транзакция: касса уменьшается (мы платим)
                    // - Оплата заказа: касса УВЕЛИЧИВАЕТСЯ (клиент платит нам)
                    if ($isOrderPayment) {
                        $cashRegister->balance += $convertedAmount; // приход денег от клиента
                    } else {
                        $cashRegister->balance -= $convertedAmount; // обычный расход
                    }
                }
                $cashRegister->save();
            }

<<<<<<< HEAD
            // Обновляем баланс клиента если:
            // 1. Долговая операция (is_debt = true)
            // 2. Обычная транзакция без связи (source_type = null)
            $isRegularTransaction = empty($data['source_type']);
            $shouldUpdateClientBalance = ($data['is_debt'] ?? false) || $isRegularTransaction;

            Log::info('Client balance update check', [
                'client_id' => $data['client_id'] ?? null,
                'is_debt' => $data['is_debt'] ?? false,
                'source_type' => $data['source_type'] ?? null,
                'isRegularTransaction' => $isRegularTransaction,
                'shouldUpdateClientBalance' => $shouldUpdateClientBalance,
                'skipClientUpdate' => $skipClientUpdate
            ]);

            if (! $skipClientUpdate && ! empty($data['client_id']) && $shouldUpdateClientBalance) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $data['client_id']]);

                Log::info('Updating client balance', [
                    'client_id' => $data['client_id'],
                    'type' => $data['type'],
                    'amount' => $convertedAmountDefault,
                    'is_debt' => $data['is_debt'] ?? false
                ]);

                // Логика зависит от того, долговая операция или обычная
                if ($data['is_debt'] ?? false) {
                    // ДОЛГОВАЯ операция (is_debt = true):
                    if ($data['type'] === 1) {
                        // type=1: клиент нам платит → уменьшаем его долг
                        $clientBalance->balance -= $convertedAmountDefault;
                    } else {
                        // type=0: мы клиенту платим → увеличиваем его долг
                        $clientBalance->balance += $convertedAmountDefault;
                    }
                } else {
                    // ОБЫЧНАЯ транзакция (is_debt = false) - расчет:
                    if ($data['type'] === 1) {
                        // type=1: приход в кассу = клиент нам ЗАПЛАТИЛ → уменьшаем его долг
                        $clientBalance->balance -= $convertedAmountDefault;
                    } else {
                        // type=0: расход из кассы = мы клиенту ЗАПЛАТИЛИ → увеличиваем его долг
                        $clientBalance->balance += $convertedAmountDefault;
                    }
                }

                $clientBalance->save();
=======
            // Обновляем баланс клиента ТОЛЬКО если это долговая операция
            if (! $skipClientUpdate && ! empty($data['client_id']) && ($data['is_debt'] ?? false)) {
                if ($data['type'] === 1) {
                    DB::table('clients')->where('id', $data['client_id'])->update([
                        'balance' => DB::raw('balance + ' . ($convertedAmountDefault + 0))
                    ]);
                } else {
                    DB::table('clients')->where('id', $data['client_id'])->update([
                        'balance' => DB::raw('balance - ' . ($convertedAmountDefault + 0))
                    ]);
                }
>>>>>>> d7c5020 (Обновлена логика работы с балансами клиентов, теперь баланс хранится непосредственно в таблице clients вместо отдельной таблицы client_balances. Упрощены запросы на получение баланса и инвалидацию кэша. Оптимизированы методы в репозиториях для работы с балансом, включая обновление и удаление транзакций, а также создание клиентов. Добавлены новые поля с десятичным форматом для различных моделей.)

                // Инвалидируем кэш клиентов и проектов после обновления баланса
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($data['client_id']);
                CacheService::invalidateProjectsCache();
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
        } catch (\Exception $e) {
            DB::rollBack();
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

            // Откатываем старый баланс кассы только если транзакция не была долговой
            if (!$oldIsDebt) {
                if ($transaction->type == 1) {
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

            // Блокируем автоматическое обновление баланса через события модели
            // т.к. мы обновляем баланс вручную ниже
            $transaction->setSkipClientBalanceUpdate(true);
            $transaction->save();

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
                $oldClientBalance = ClientBalance::firstOrCreate(['client_id' => $oldClientId]);

                if ($oldCurrencyId !== $defaultCurrencyId) {
                    $oldConvertedAmountDefault = CurrencyConverter::convert($oldOrigAmount, $currencies[$oldCurrencyId], $currencies[$defaultCurrencyId]);
                } else {
                    $oldConvertedAmountDefault = $oldOrigAmount;
                }

                // Откат старого баланса: делаем обратную операцию
                if ($oldIsDebt) {
                    // Долговая операция: откатываем как было при создании
                    if ($transaction->type == 1) {
                        // Доход: при создании был -=, откатываем через +=
                        $oldClientBalance->balance += $oldConvertedAmountDefault;
                    } else {
                        // Расход: при создании был +=, откатываем через -=
                        $oldClientBalance->balance -= $oldConvertedAmountDefault;
                    }
                } else {
                    // Обычная транзакция: откатываем как было при создании
                    if ($transaction->type == 1) {
                        // Доход: при создании был -=, откатываем через +=
                        $oldClientBalance->balance += $oldConvertedAmountDefault;
                    } else {
                        // Расход: при создании был +=, откатываем через -=
                        $oldClientBalance->balance -= $oldConvertedAmountDefault;
                    }
                }

                $oldClientBalance->save();
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
                    'current_balance' => $clientBalance->balance
                ]);

                // Откатываем старый баланс клиента если была долговая операция ИЛИ обычная транзакция И клиент не изменился
                $oldIsRegularTransaction = empty($oldSourceType);
                if (($oldIsDebt || $oldIsRegularTransaction) && $oldClientId === $transaction->client_id) {
                    if ($oldCurrencyId !== $defaultCurrencyId) {
                        $oldConvertedAmountDefault = CurrencyConverter::convert($oldOrigAmount, $currencies[$oldCurrencyId], $currencies[$defaultCurrencyId]);
                    } else {
                        $oldConvertedAmountDefault = $oldOrigAmount;
                    }

                    // Откат старого баланса: делаем обратную операцию
<<<<<<< HEAD
                    if ($oldIsDebt) {
                        // Долговая операция: откатываем как было при создании
                        if ($transaction->type == 1) {
                            // Доход: при создании был -=, откатываем через +=
                            $clientBalance->balance += $oldConvertedAmountDefault;
                        } else {
                            // Расход: при создании был +=, откатываем через -=
                            $clientBalance->balance -= $oldConvertedAmountDefault;
                        }
                    } else {
                        // Обычная транзакция: откатываем как было при создании
                        if ($transaction->type == 1) {
                            // Доход: клиент нам платил → баланс уменьшался, откатываем через +=
                            $clientBalance->balance += $oldConvertedAmountDefault;
                        } else {
                            // Расход: мы клиенту платили → баланс увеличивался, откатываем через -=
                            $clientBalance->balance -= $oldConvertedAmountDefault;
                        }
=======
                    if ($transaction->type == 1) {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance - ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    } else {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance + ' . ($oldConvertedAmountDefault + 0))
                        ]);
>>>>>>> d7c5020 (Обновлена логика работы с балансами клиентов, теперь баланс хранится непосредственно в таблице clients вместо отдельной таблицы client_balances. Упрощены запросы на получение баланса и инвалидацию кэша. Оптимизированы методы в репозиториях для работы с балансом, включая обновление и удаление транзакций, а также создание клиентов. Добавлены новые поля с десятичным форматом для различных моделей.)
                    }
                }

                // Применяем новый баланс клиента если это долговая операция ИЛИ обычная транзакция
                $isRegularTransaction = empty($transaction->source_type);
                $shouldUpdateClientBalance = $transaction->is_debt || $isRegularTransaction;

                Log::info('Client balance update details', [
                    'transaction_id' => $id,
                    'source_type' => $transaction->source_type,
                    'isRegularTransaction' => $isRegularTransaction,
                    'is_debt' => $transaction->is_debt,
                    'shouldUpdateClientBalance' => $shouldUpdateClientBalance,
                    'type' => $transaction->type,
                    'newAmount' => $newOrigAmount,
                    'oldBalance' => $clientBalance->balance
                ]);

                if ($shouldUpdateClientBalance) {
                    if ($newCurrencyId !== $defaultCurrencyId) {
                        $newConvertedAmountDefault = CurrencyConverter::convert($newOrigAmount, $fromCurrency, $currencies[$defaultCurrencyId]);
                    } else {
                        $newConvertedAmountDefault = $newOrigAmount;
                    }

<<<<<<< HEAD
                    // Применяем новую сумму как при создании транзакции
                    if ($transaction->is_debt) {
                        // Долговая операция: применяем новую сумму как при создании
                        if ($transaction->type == 1) {
                            // Доход: клиент нам платит - уменьшаем его долг
                            $clientBalance->balance -= $newConvertedAmountDefault;
                        } else {
                            // Расход: мы клиенту платим - увеличиваем его долг
                            $clientBalance->balance += $newConvertedAmountDefault;
                        }
                    } else {
                        // Обычная транзакция: применяем новую сумму как при создании
                        if ($transaction->type == 1) {
                            // Доход: клиент нам платит → баланс уменьшается
                            $clientBalance->balance -= $newConvertedAmountDefault;
                        } else {
                            // Расход: мы клиенту платим → баланс увеличивается
                            $clientBalance->balance += $newConvertedAmountDefault;
                        }
=======
                    // Применение нового баланса: как при создании
                    if ($transaction->type == 1) {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance + ' . ($newConvertedAmountDefault + 0))
                        ]);
                    } else {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance - ' . ($newConvertedAmountDefault + 0))
                        ]);
>>>>>>> d7c5020 (Обновлена логика работы с балансами клиентов, теперь баланс хранится непосредственно в таблице clients вместо отдельной таблицы client_balances. Упрощены запросы на получение баланса и инвалидацию кэша. Оптимизированы методы в репозиториях для работы с балансом, включая обновление и удаление транзакций, а также создание клиентов. Добавлены новые поля с десятичным форматом для различных моделей.)
                    }

                    Log::info('Client balance updated', [
                        'transaction_id' => $id,
                        'newBalance' => $clientBalance->balance,
                        'oldAmount' => $oldConvertedAmountDefault,
                        'newAmount' => $newConvertedAmountDefault
                    ]);
                }

                // Сохраняем только если были изменения
<<<<<<< HEAD
                $oldIsRegularTransaction = empty($oldSourceType ?? '');
                if ($oldIsDebt || $transaction->is_debt || $oldIsRegularTransaction || $isRegularTransaction) {
                    $clientBalance->save();
=======
                if ($oldIsDebt || $transaction->is_debt) {
>>>>>>> d7c5020 (Обновлена логика работы с балансами клиентов, теперь баланс хранится непосредственно в таблице clients вместо отдельной таблицы client_balances. Упрощены запросы на получение баланса и инвалидацию кэша. Оптимизированы методы в репозиториях для работы с балансом, включая обновление и удаление транзакций, а также создание клиентов. Добавлены новые поля с десятичным форматом для различных моделей.)

                    // Инвалидируем кэш клиентов и проектов после обновления баланса
                    CacheService::invalidateClientsCache();
                    CacheService::invalidateClientBalanceCache($transaction->client_id);
                    CacheService::invalidateProjectsCache();
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

            $transaction->setSkipClientBalanceUpdate(true);
            $transaction->delete();

<<<<<<< HEAD
            // Обновляем баланс клиента если это была долговая операция ИЛИ обычная транзакция
            $isRegularTransaction = empty($transaction->source_type);
            $shouldUpdateClientBalance = $transaction->is_debt || $isRegularTransaction;

            if (! $skipClientUpdate && $transaction->client_id && $shouldUpdateClientBalance) {
                $clientBalance = ClientBalance::firstOrCreate(
                    ['client_id' => $transaction->client_id],
                    ['balance' => 0]
                );
                if ($transaction->is_debt) {
                    // Долговая операция: откатываем как было при создании
                    if ($transaction->type == 1) {
                        // Доход: клиент нам платил - при удалении уменьшаем баланс
                        $clientBalance->balance -= $convertedAmountDefault;
                    } else {
                        // Расход: мы клиенту платили - при удалении увеличиваем баланс
                        $clientBalance->balance += $convertedAmountDefault;
                    }
                } else {
                    // Обычная транзакция: откатываем как было при создании
                    if ($transaction->type == 1) {
                        // Доход: клиент нам платил → баланс уменьшался, при удалении увеличиваем
                        $clientBalance->balance += $convertedAmountDefault;
                    } else {
                        // Расход: мы клиенту платили → баланс увеличивался, при удалении уменьшаем
                        $clientBalance->balance -= $convertedAmountDefault;
                    }
=======
            // Обновляем баланс клиента ТОЛЬКО если это была долговая операция
            if (! $skipClientUpdate && $transaction->client_id && $transaction->is_debt) {
                if ($transaction->type == 1) {
                    // Доход: клиент нам платил - при удалении уменьшаем баланс
                    DB::table('clients')->where('id', $transaction->client_id)->update([
                        'balance' => DB::raw('balance - ' . ($convertedAmountDefault + 0))
                    ]);
                } else {
                    // Расход: мы клиенту платили - при удалении увеличиваем баланс
                    DB::table('clients')->where('id', $transaction->client_id)->update([
                        'balance' => DB::raw('balance + ' . ($convertedAmountDefault + 0))
                    ]);
>>>>>>> d7c5020 (Обновлена логика работы с балансами клиентов, теперь баланс хранится непосредственно в таблице clients вместо отдельной таблицы client_balances. Упрощены запросы на получение баланса и инвалидацию кэша. Оптимизированы методы в репозиториях для работы с балансом, включая обновление и удаление транзакций, а также создание клиентов. Добавлены новые поля с десятичным форматом для различных моделей.)
                }

                // Инвалидируем кэш клиентов и проектов после обновления баланса
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($transaction->client_id);
                CacheService::invalidateProjectsCache();
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
