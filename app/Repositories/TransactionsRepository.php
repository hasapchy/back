<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\OrderTransaction;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use Illuminate\Support\Facades\DB;

class TransactionsRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20, $cash_id = null, $date_filter_type = null, $order_id = null, $search = null, $transaction_type = null, $source = null)
    {
        $paginator = Transaction::leftJoin('cash_registers as cash_registers', 'transactions.cash_id', '=', 'cash_registers.id')
            ->leftJoin('cash_register_users as cash_register_users', 'cash_registers.id', '=', 'cash_register_users.cash_register_id')
            ->where('cash_register_users.user_id', $userUuid)
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
                return $query->whereHas('orders', function ($q) use ($order_id) {
                    $q->where('orders.id', $order_id);
                });
            })
            ->when($search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('transactions.id', 'like', "%{$search}%")
                        ->orWhere('clients.first_name', 'like', "%{$search}%")
                        ->orWhere('clients.last_name', 'like', "%{$search}%")
                        ->orWhere('clients.contact_person', 'like', "%{$search}%");
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
                            $q->whereHas('cashTransfersFrom')
                                ->orWhereHas('cashTransfersTo');
                        });
                    default:
                        return $query;
                }
            })
            ->when($source, function ($query, $source) {
                if (empty($source)) return $query;

                return $query->where(function ($q) use ($source) {
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

                    if (count($conditions) === 1) {
                        // Если выбран только один источник
                        $this->applySingleSourceFilter($q, $conditions[0]);
                    } else {
                        // Если выбрано несколько источников
                        $q->where(function ($subQ) use ($conditions) {
                            foreach ($conditions as $index => $condition) {
                                if ($index === 0) {
                                    $this->applySingleSourceFilter($subQ, $condition);
                                } else {
                                    $subQ->orWhere(function ($orQ) use ($condition) {
                                        $this->applySingleSourceFilter($orQ, $condition);
                                    });
                                }
                            }
                        });
                    }
                });
            })
            ->orderBy('id', 'desc')
            ->select('transactions.id as id')
            ->paginate($perPage);

        $items_ids = $paginator->pluck('id')->toArray();
        $items = $this->getItems($items_ids);
        $ordered_items = $items->sortBy(function ($item) use ($items_ids) {
            return array_search($item->id, $items_ids);
        })->values();

        $paginator->setCollection($ordered_items);
        return $paginator;
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
                $query->whereHas('sales');
                break;
            case 'order':
                $query->whereHas('orders');
                break;
            case 'other':
                $query->whereNull('transactions.project_id')
                    ->whereDoesntHave('sales')
                    ->whereDoesntHave('orders');
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
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $return_id ? $transaction->id : true;
    }

    public function updateItem($id, $data)
    {
        $item = Transaction::find($id);
        $item->client_id = $data['client_id'];
        $item->category_id = $data['category_id'];
        $item->project_id = $data['project_id'];
        $item->date = $data['date'];
        $item->note = $data['note'];
        $item->save();

        return true;
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

            // Получаем ID всех нужных валют
            $defaultCurrencyId = Currency::where('is_default', true)->value('id');

            $currencyIds = array_unique([
                $transaction->currency_id,
                $cashRegister->currency_id,
                $defaultCurrencyId,
            ]);

            // Загружаем одним запросом
            $currencies = Currency::whereIn('id', $currencyIds)->get()->keyBy('id');

            // Назначаем
            $fromCurrency = $currencies[$transaction->currency_id];
            $toCurrency = $currencies[$cashRegister->currency_id];
            $defaultCurrency = $currencies[$defaultCurrencyId];


            // Конвертируем сумму транзакции в валюту кассы
            if ($fromCurrency->id !== $toCurrency->id) {
                $convertedAmount = CurrencyConverter::convert($transaction->amount, $fromCurrency, $toCurrency);
            } else {
                $convertedAmount = $transaction->amount;
            }

            // Применяем округление если оно включено в кассе
            $convertedAmount = $cashRegister->roundAmount($convertedAmount);

            // Конвертируем сумму в валюту по умолчанию для клиента
            if ($fromCurrency->id !== $defaultCurrency->id) {
                $convertedAmountDefault = CurrencyConverter::convert($transaction->amount, $fromCurrency, $defaultCurrency);
            } else {
                $convertedAmountDefault = $transaction->amount;
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
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getTotalByOrderId($userId, $orderId)
    {
        return Transaction::where('user_id', $userId)
            ->whereHas('orders', function ($query) use ($orderId) {
                $query->where('orders.id', $orderId);
            })
            ->sum('orig_amount');
    }

    public function userHasPermissionToCashRegister($userUuid, $cashRegisterId)
    {
        return CashRegister::query()
            ->Where('id', $cashRegisterId)
            ->whereHas('users', function($query) use ($userUuid) {
                $query->where('user_id', $userUuid);
            })->exists();
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
        $query->leftJoin('cash_registers as cash_registers', 'transactions.cash_id', '=', 'cash_registers.id');
        $query->leftJoin('currencies as cash_register_currencies', 'cash_registers.currency_id', '=', 'cash_register_currencies.id');
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

        $client_ids = $items->pluck('client_id')->toArray();
        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids);

        foreach ($items as $item) {
            $item = (object) $item->toArray();
            $item->client = $clients->firstWhere('id', $item->client_id);
        }
        return $items;
    }
}
