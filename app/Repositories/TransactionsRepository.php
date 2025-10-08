<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use App\Repositories\ProjectsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionsRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 10, $page = 1, $cash_id = null, $date_filter_type = null, $order_id = null, $search = null, $transaction_type = null, $source = null, $project_id = null, $start_date = null, $end_date = null)
    {
        try {
            // Создаем уникальный ключ кэша (привязываем к пользователю и фильтрам/кассе, без company_id)
            $searchKey = $search !== null ? md5((string)$search) : 'null';
            $cacheKey = "transactions_paginated_{$userUuid}_{$perPage}_{$cash_id}_{$date_filter_type}_{$order_id}_{$searchKey}_{$transaction_type}_{$source}_{$project_id}_{$start_date}_{$end_date}";

            return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page, $cash_id, $date_filter_type, $order_id, $search, $transaction_type, $source, $project_id, $start_date, $end_date) {
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
                        'client_balance' => DB::table('client_balances')
                            ->select('balance')
                            ->whereColumn('client_id', 'transactions.client_id')
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

                        return $q->where(function ($subQ) use ($source) {
                            $hasConditions = false;

                            if (in_array('sale', $source)) {
                                $subQ->where('source_type', 'App\\Models\\Sale');
                                $hasConditions = true;
                            }
                            if (in_array('order', $source)) {
                                if ($hasConditions) {
                                    $subQ->orWhere('source_type', 'App\\Models\\Order');
                                } else {
                                    $subQ->where('source_type', 'App\\Models\\Order');
                                }
                                $hasConditions = true;
                            }
                            if (in_array('other', $source)) {
                                if ($hasConditions) {
                                    $subQ->orWhere(function ($otherQ) {
                                        $otherQ->whereNull('source_type')
                                            ->orWhereNotIn('source_type', ['App\\Models\\Sale', 'App\\Models\\Order']);
                                    });
                                } else {
                                    $subQ->whereNull('source_type')
                                        ->orWhereNotIn('source_type', ['App\\Models\\Sale', 'App\\Models\\Order']);
                                }
                            }
                        });
                    })
                    // Фильтрация по проекту
                    ->when($project_id, function ($q, $project_id) {
                        return $q->where('transactions.project_id', $project_id);
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
                            'balance_amount' => $transaction->client_balance ?? 0,
                        ] : null,
                        'note' => $transaction->note,
                        'date' => $transaction->date,
                        'created_at' => $transaction->created_at,
                        'updated_at' => $transaction->updated_at,
                    ];
                });

                return $paginatedResults;
            }, (int)$page);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Быстрый поиск транзакций с оптимизированным кэшированием
     */
    public function fastSearch($userUuid, $search, $perPage = 20)
    {
        $cacheKey = "transactions_fast_search_{$userUuid}_" . md5((string)$search) . "_{$perPage}";

        return CacheService::rememberSearch($cacheKey, function () use ($userUuid, $search, $perPage) {
            return Transaction::select([
                'transactions.id',
                'transactions.type',
                'transactions.amount',
                'transactions.cash_id',
                'transactions.date',
                'transactions.created_at'
            ])
                ->leftJoin('cash_registers as cash_registers', 'transactions.cash_id', '=', 'cash_registers.id')
                ->leftJoin('cash_register_users as cash_register_users', 'cash_registers.id', '=', 'cash_register_users.cash_register_id')
                ->where('cash_register_users.user_id', $userUuid)
                ->where(function ($q) use ($search) {
                    $q->where('transactions.id', 'like', "%{$search}%")
                        ->orWhere('transactions.amount', 'like', "%{$search}%")
                        ->orWhere('transactions.note', 'like', "%{$search}%");
                })
                ->orderBy('transactions.created_at', 'desc')
                ->paginate($perPage);
        });
    }

    /**
     * Применяет фильтр по одному источнику средств
     */
    private function applySingleSourceFilter($query, $source)
    {
        switch ($source) {
            case 'project':
                $query->whereNotNull('transactions.project_id');
                break;
            case 'sale':
                $query->where('transactions.source_type', 'App\Models\Sale');
                break;
            case 'order':
                $query->where('transactions.source_type', 'App\Models\Order');
                break;
            case 'other':
                $query->where(function ($q) {
                    $q->whereNull('transactions.source_type')
                        ->orWhereNotIn('transactions.source_type', ['App\Models\Sale', 'App\Models\Order']);
                });
                break;
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

            // Обновляем кассу только если это не долговая операция и касса указана
            if (!($data['is_debt'] ?? false) && $cashRegister) {
                if ((int)$data['type'] === 1) {
                    $cashRegister->balance += $convertedAmount;
                } else {
                    $cashRegister->balance -= $convertedAmount;
                }
                $cashRegister->save();
            }

            if (! $skipClientUpdate && ! empty($data['client_id'])) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $data['client_id']]);
                if ($data['type'] === 1) {
                    // Доход: клиент нам платит - уменьшаем его долг (увеличиваем баланс)
                    $clientBalance->balance += $convertedAmountDefault;
                } else {
                    // Расход: мы клиенту платим - увеличиваем его долг (уменьшаем баланс)
                    $clientBalance->balance -= $convertedAmountDefault;
                }
                $clientBalance->save();

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

        // Если изменяется сумма, валюта или статус долга, нужно пересчитать баланс кассы
        $needsBalanceUpdate = isset($data['orig_amount']) || isset($data['currency_id']) || isset($data['is_debt']);

        if ($needsBalanceUpdate) {
            return $this->updateItemWithBalanceRecalculation($id, $data);
        }

        // Обычное обновление без изменения суммы
        $transaction->client_id = $data['client_id'];
        $transaction->category_id = $data['category_id'];
        $transaction->project_id = $data['project_id'];
        $transaction->date = $data['date'];
        $transaction->note = $data['note'];
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
            $transaction->save();

            // Применяем новый баланс кассы только если транзакция не стала долговой
            if (!$transaction->is_debt) {
                if ($transaction->type == 1) {
                    $cashRegister->balance += $newConvertedAmount;
                } else {
                    $cashRegister->balance -= $newConvertedAmount;
                }
                $cashRegister->save();
            }

            // Обновляем баланс клиента если нужно
            if ($transaction->client_id) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $transaction->client_id]);

                // Откатываем старый баланс клиента
                if ($oldCurrencyId !== $defaultCurrencyId) {
                    $oldConvertedAmountDefault = CurrencyConverter::convert($oldAmount, $currencies[$oldCurrencyId], $currencies[$defaultCurrencyId]);
                } else {
                    $oldConvertedAmountDefault = $oldAmount;
                }

                if ($transaction->type == 1) {
                    $clientBalance->balance += $oldConvertedAmountDefault;
                } else {
                    $clientBalance->balance -= $oldConvertedAmountDefault;
                }

                // Применяем новый баланс клиента
                if ($newCurrencyId !== $defaultCurrencyId) {
                    $newConvertedAmountDefault = CurrencyConverter::convert($newConvertedAmount, $fromCurrency, $currencies[$defaultCurrencyId]);
                } else {
                    $newConvertedAmountDefault = $newConvertedAmount;
                }

                if ($transaction->type == 1) {
                    $clientBalance->balance -= $newConvertedAmountDefault;
                } else {
                    $clientBalance->balance += $newConvertedAmountDefault;
                }

                $clientBalance->save();

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

            if (! $skipClientUpdate && $transaction->client_id) {
                $clientBalance = ClientBalance::firstOrCreate(
                    ['client_id' => $transaction->client_id],
                    ['balance' => 0]
                );
                if ($transaction->type == 1) {
                    // Доход: клиент нам платил - при удалении уменьшаем баланс
                    $clientBalance->balance -= $convertedAmountDefault;
                } else {
                    // Расход: мы клиенту платили - при удалении увеличиваем баланс
                    $clientBalance->balance += $convertedAmountDefault;
                }
                $clientBalance->save();

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
                    DB::raw('(SELECT COALESCE(balance, 0) FROM client_balances WHERE client_id = clients.id LIMIT 1) as balance_amount')
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
