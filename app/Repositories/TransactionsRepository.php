<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\OrderTransaction;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionsRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20, $cash_id = null, $date_filter_type = null, $order_id = null, $search = null, $transaction_type = null, $source = null)
    {
        try {
            // Создаем уникальный ключ кэша
            $cacheKey = "transactions_paginated_{$userUuid}_{$perPage}_{$cash_id}_{$date_filter_type}_{$order_id}_{$search}_{$transaction_type}_" . json_encode($source);

            return CacheService::remember($cacheKey, function () use ($userUuid, $perPage, $cash_id, $date_filter_type, $order_id, $search, $transaction_type, $source) {
                // Используем with() для загрузки связей вместо сложных JOIN'ов
                $query = Transaction::with([
                    'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at',
                    'currency:id,name,code,symbol',
                    'cashRegister:id,name,currency_id',
                    'cashRegister.currency:id,name,code,symbol',
                    'category:id,name,type',
                    'project:id,name',
                    'user:id,name'
                    // Временно убираем проблемные связи для отладки
                    // 'cashTransfersFrom:id,tr_id_from',
                    // 'cashTransfersTo:id,tr_id_to',
                    // 'orderTransactions:id,order_id,transaction_id'
                ])
                    ->whereHas('cashRegister.cashRegisterUsers', function($q) use ($userUuid) {
                        $q->where('user_id', $userUuid);
                    })
                    ->when($cash_id, function ($query, $cash_id) {
                        return $query->where('transactions.cash_id', $cash_id);
                    })
                    ->when($date_filter_type, function ($query, $date_filter_type) {
                        switch ($date_filter_type) {
                            case 'today':
                                return $query->whereDate('transactions.date', '=', now()->toDateString());
                            case 'yesterday':
                                return $query->whereDate('transactions.date', '=', now()->subDay()->toDateString());
                            case 'this_week':
                                return $query->whereBetween('transactions.date', [now()->startOfWeek(), now()->endOfWeek()]);
                            case 'last_week':
                                return $query->whereBetween('transactions.date', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()]);
                            case 'this_month':
                                return $query->whereBetween('transactions.date', [now()->startOfMonth(), now()->endOfMonth()]);
                            case 'last_month':
                                return $query->whereBetween('transactions.date', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()]);
                            default:
                                return $query;
                        }
                    })
                    ->when($order_id, function ($query, $order_id) {
                        return $query->whereExists(function ($subQuery) use ($order_id) {
                            $subQuery->select(DB::raw(1))
                                ->from('order_transactions')
                                ->whereColumn('order_transactions.transaction_id', 'transactions.id')
                                ->where('order_transactions.order_id', $order_id);
                        });
                    })
                    ->when($search, function ($query, $search) {
                        return $query->where(function ($q) use ($search) {
                            $q->where('transactions.id', 'like', "%{$search}%")
                                ->orWhereHas('client', function($clientQuery) use ($search) {
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
                            $conditions = [];

                            if (in_array('project', $source)) {
                                $conditions[] = 'project';
                            }
                            if (in_array('sale', $source)) {
                                $conditions[] = 'sale';
                            }
                            if (in_array('order', $source)) {
                                $conditions[] = 'order';
                            }
                            if (in_array('other', $source)) {
                                $conditions[] = 'other';
                            }

                            if (!empty($conditions)) {
                                $subQ->where(function ($orQ) use ($conditions) {
                                    foreach ($conditions as $condition) {
                                        $orQ->orWhere(function ($subOrQ) use ($condition) {
                                            $this->applySingleSourceFilter($subOrQ, $condition);
                                        });
                                    }
                                });
                            }
                        });
                    })
                    // Фильтрация по доступу к проектам
                    ->where(function($q) use ($userUuid) {
                        $q->whereNull('transactions.project_id') // Транзакции без проекта
                          ->orWhereHas('project.projectUsers', function($subQuery) use ($userUuid) {
                              $subQuery->where('user_id', $userUuid);
                          });
                    })
                    ->orderBy('transactions.id', 'desc');

                $paginatedResults = $query->paginate($perPage);

                // Принудительно загружаем связи для всех элементов пагинации
                $paginatedResults->getCollection()->load([
                    'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at',
                    'currency:id,name,code,symbol',
                    'cashRegister:id,name,currency_id',
                    'cashRegister.currency:id,name,code,symbol',
                    'category:id,name,type',
                    'project:id,name',
                    'user:id,name'
                    // Временно убираем проблемные связи для отладки
                    // 'cashTransfersFrom:id,tr_id_from',
                    // 'cashTransfersTo:id,tr_id_to',
                    // 'orderTransactions:id,order_id,transaction_id'
                ]);

                // Преобразуем данные в тот же формат, что и в методе getItems
                $paginatedResults->getCollection()->transform(function ($transaction) {
                    return (object) [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'is_transfer' => $this->isTransfer($transaction),
                        'is_sale' => $this->isSale($transaction),
                        'is_receipt' => $this->isReceipt($transaction),
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
                        ] : null,
                        'note' => $transaction->note,
                        'date' => $transaction->date,
                        'created_at' => $transaction->created_at,
                        'updated_at' => $transaction->updated_at,
                    ];
                });

                return $paginatedResults;
            });
        } catch (\Exception $e) {
            Log::error('TransactionsRepository: Ошибка в getItemsWithPagination', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'userUuid' => $userUuid
            ]);
            throw $e;
        }
    }

    /**
     * Быстрый поиск транзакций с оптимизированным кэшированием
     */
    public function fastSearch($userUuid, $search, $perPage = 20)
    {
        $cacheKey = "transactions_fast_search_{$userUuid}_{$search}_{$perPage}";

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
                $query->whereExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('sales')
                        ->whereColumn('sales.transaction_id', 'transactions.id');
                });
                break;
            case 'order':
                $query->whereExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('order_transactions')
                        ->whereColumn('order_transactions.transaction_id', 'transactions.id');
                });
                break;
            case 'other':
                $query->whereNull('transactions.project_id')
                    ->whereNotExists(function ($subQuery) {
                        $subQuery->select(DB::raw(1))
                            ->from('sales')
                            ->whereColumn('sales.transaction_id', 'transactions.id');
                    })
                    ->whereNotExists(function ($subQuery) {
                        $subQuery->select(DB::raw(1))
                            ->from('order_transactions')
                            ->whereColumn('order_transactions.transaction_id', 'transactions.id');
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
            // Удалено поле order_id - теперь используется связующая таблица
            $transaction->setSkipClientBalanceUpdate(true);
            $transaction->save();

            // Создаем связь с заказом если указан order_id
            if (!empty($data['order_id'])) {
                OrderTransaction::create([
                    'order_id' => $data['order_id'],
                    'transaction_id' => $transaction->id,
                ]);
            }

            if ((int)$data['type'] === 1) {
                $cashRegister->balance += $convertedAmount;
            } else {
                $cashRegister->balance -= $convertedAmount;
            }
            $cashRegister->save();

            if (! $skipClientUpdate && ! empty($data['client_id'])) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $data['client_id']]);
                if ($data['type'] === 1) {
                    $clientBalance->balance -= $convertedAmountDefault;
                } else {
                    $clientBalance->balance += $convertedAmountDefault;
                }
                $clientBalance->save();
            }

            DB::commit();

            // Инвалидируем кэш транзакций
            $this->invalidateTransactionsCache();

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

        // Если изменяется сумма или валюта, нужно пересчитать баланс кассы
        $needsBalanceUpdate = isset($data['orig_amount']) || isset($data['currency_id']);

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

        // Инвалидируем кэш транзакций
        $this->invalidateTransactionsCache();

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

            // Откатываем старый баланс кассы
            if ($transaction->type == 1) {
                $cashRegister->balance -= $oldAmount;
            } else {
                $cashRegister->balance += $oldAmount;
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

            // Применяем новый баланс кассы
            if ($transaction->type == 1) {
                $cashRegister->balance += $newConvertedAmount;
            } else {
                $cashRegister->balance -= $newConvertedAmount;
            }
            $cashRegister->save();

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
            }

            DB::commit();

            // Инвалидируем кэш транзакций
            $this->invalidateTransactionsCache();

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

            // Корректируем баланс кассы
            if ($transaction->type == 1) {
                $cashRegister->balance -= $convertedAmount;
            } else {
                $cashRegister->balance += $convertedAmount;
            }
            $cashRegister->save();

            // Удаляем связи с заказами
            OrderTransaction::where('transaction_id', $transaction->id)->delete();

            $transaction->setSkipClientBalanceUpdate(true);
            $transaction->delete();

            if (! $skipClientUpdate && $transaction->client_id) {
                $clientBalance = ClientBalance::firstOrCreate(
                    ['client_id' => $transaction->client_id],
                    ['balance' => 0]
                );
                if ($transaction->type == 1) {
                    $clientBalance->balance += $convertedAmountDefault;
                } else {
                    $clientBalance->balance -= $convertedAmountDefault;
                }
                $clientBalance->save();
            }

            DB::commit();

            // Инвалидируем кэш транзакций
            $this->invalidateTransactionsCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getTotalByOrderId($userId, $orderId)
    {
        return Transaction::where('transactions.user_id', $userId)
            ->whereExists(function ($subQuery) use ($orderId) {
                $subQuery->select(DB::raw(1))
                    ->from('order_transactions')
                    ->whereColumn('order_transactions.transaction_id', 'transactions.id')
                    ->where('order_transactions.order_id', $orderId);
            })
            ->sum('orig_amount');
    }

    private function isTransfer($transaction)
    {
        return $transaction->cashTransfersFrom()->exists() || $transaction->cashTransfersTo()->exists();
    }

    private function isSale($transaction)
    {
        return $transaction->sales()->exists();
    }

    private function isReceipt($transaction)
    {
        return $transaction->warehouseReceipts()->exists();
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
    private function invalidateTransactionsCache()
    {
        // Очищаем кэш транзакций
        $keys = [
            'transactions_paginated_*',
            'transactions_fast_search_*'
        ];

        foreach ($keys as $key) {
            if (str_contains($key, '*')) {
                // Для паттернов с wildcard очищаем весь кэш
                \Illuminate\Support\Facades\Cache::flush();
                break;
            }
        }
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
                ->select('id', 'first_name', 'last_name', 'contact_person')
                ->get()
                ->keyBy('id');
        }

        foreach ($items as $item) {
            $item = (object) $item->toArray();
            $item->client = $clients->get($item->client_id);
        }
        return $items;
    }
}
