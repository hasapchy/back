<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use Illuminate\Support\Facades\DB;

class TransactionsRepository
{
    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $cash_id = null, $date_filter_type = null)
    {
        $paginator = Transaction::leftJoin('cash_registers as cash_registers', 'transactions.cash_id', '=', 'cash_registers.id')
            ->whereJsonContains('cash_registers.users', (string) $userUuid)
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
                        // case 'custom':
                        //     // Assuming you have 'start_date' and 'end_date' in the request data
                        //     return $query->whereBetween('transactions.date', [request('start_date'), request('end_date')]);
                        // case 'all_time':
                    default:
                        return $query;
                }
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

    // Получение всего списка
    // public function getAllItems($userUuid)
    // {
    //     // $items = Transaction::leftJoin('currencies as currencies', 'cash_registers.currency_id', '=', 'currencies.id')
    //     //     ->select('cash_registers.*', 'currencies.name as currency_name', 'currencies.code as currency_code', 'currencies.symbol as currency_symbol')
    //     //     ->whereJsonContains('cash_registers.users', (string) $userUuid)
    //     //     ->get();
    //     $items = $this->getItems();
    //     return $items;
    // }


    // Создание
    public function createItem($data, $return_id = false)
    {
        // Касса
        $cashRegister = CashRegister::find($data['cash_id']);
        // Указанная сумма
        $originalAmount = $data['orig_amount'];
        // Валюта указанной суммы
        $fromCurrency = Currency::find($data['currency_id']);
        // Валюта кассы
        $toCurrency   = Currency::find($cashRegister->currency_id);
        // Валюта по умолчанию
        $defaultCurrency = Currency::where('is_default', true)->first();

        // Конвертируем сумму в валюту кассы
        if ($fromCurrency->id === $toCurrency->id) {
            $convertedAmount = $originalAmount;
        } else {
            $convertedAmount = CurrencyConverter::convert($originalAmount, $fromCurrency, $toCurrency);
        }

        // Конвертируем сумму в валюту по умолчанию
        if ($fromCurrency->id !== $defaultCurrency->id) {
            $convertedAmountDefault = CurrencyConverter::convert($originalAmount, $fromCurrency, $defaultCurrency);
        } else {
            $convertedAmountDefault = $originalAmount;
        }

        // Начинаем транзакцию
        DB::beginTransaction();

        try {
            // Создаем транзакцию
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

            // Пропускаем обновление баланса клиента
            $transaction->setSkipClientBalanceUpdate(true);


            $transaction->save();

            // Вычитаем сумму из кассы
            if ($data['type'] === 1) {
                $cashRegister->balance += $convertedAmount;
            } else {
                $cashRegister->balance -= $convertedAmount;
            }

            $cashRegister->save();

            $client = Client::find($data['client_id']);
            if ($client) {
                // Вычитаем сумму из баланса клиента
                $client_balance = ClientBalance::firstOrCreate(
                    ['client_id' => $client->id],
                    ['balance' => 0]
                );
                if ($data['type'] === 1) {
                    $client_balance->balance -= $convertedAmountDefault;
                } else {
                    $client_balance->balance += $convertedAmountDefault;
                }
                $client_balance->save();
            }

            // Фиксируем транзакцию
            DB::commit();
        } catch (\Exception $e) {
            // Откатываем транзакцию в случае ошибки
            DB::rollBack();
            throw $e;
        }

        return $return_id ? $transaction->id : true;
    }

    // Обновление
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

    // Удаление
    public function deleteItem($id)
    {
        $item = Transaction::find($id);
        if (!$item) {
            return false;
        }
        $item->delete();
        return true;
    }


    public function userHasPermissionToCashRegister($userUuid, $cashRegisterId)
    {
        return CashRegister::query()
            ->Where('id', $cashRegisterId)
            ->whereJsonContains('cash_registers.users', (string) $userUuid)->exists();
    }

    private function getItems(array $ids = [])
    {
        $query = Transaction::query();
        // присоединяем таблицу пользователей
        $query->leftJoin('users as users', 'transactions.user_id', '=', 'users.id');
        // Присоединяем таблицу валют оригинальной транзакции
        $query->leftJoin('currencies as currencies', 'transactions.currency_id', '=', 'currencies.id');
        // Присоединяем таблицу касс
        $query->leftJoin('cash_registers as cash_registers', 'transactions.cash_id', '=', 'cash_registers.id');
        // Присоединяем таблицу валют кассы
        $query->leftJoin('currencies as cash_register_currencies', 'cash_registers.currency_id', '=', 'cash_register_currencies.id');
        // Присоединяем таблицу категорий
        $query->leftJoin('transaction_categories as transaction_categories', 'transactions.category_id', '=', 'transaction_categories.id');
        // Присоединяем таблицу проектов
        $query->leftJoin('projects as projects', 'transactions.project_id', '=', 'projects.id');
        // Присоединяем cash_transfers, чтобы проверить, есть ли transaction.id в tr_id_from или tr_id_to
        $query->leftJoin('cash_transfers as cash_transfers_from', 'transactions.id', '=', 'cash_transfers_from.tr_id_from');
        $query->leftJoin('cash_transfers as cash_transfers_to', 'transactions.id', '=', 'cash_transfers_to.tr_id_to');

        // Берем нужные по массиву ид
        $query->whereIn('transactions.id', $ids);
        // Выбираем поля
        $query->select(
            // Поля из таблицы транзакций
            'transactions.id as id',
            'transactions.type as type',
            // проверка на трансфер
            DB::raw('CASE 
            WHEN cash_transfers_from.tr_id_from IS NOT NULL 
              OR cash_transfers_to.tr_id_to IS NOT NULL 
                THEN true 
                ELSE false 
            END as is_transfer'),
            // Поля из таблицы касс
            'transactions.cash_id as cash_id',
            'cash_registers.name as cash_name',
            // Сумма в валюте кассы
            'transactions.amount as cash_amount',
            'cash_register_currencies.id as cash_currency_id',
            'cash_register_currencies.name as cash_currency_name',
            'cash_register_currencies.code as cash_currency_code',
            'cash_register_currencies.symbol as cash_currency_symbol',
            // Сумма в валюте транзакции
            'transactions.orig_amount as orig_amount',
            'currencies.id as orig_currency_id',
            'currencies.name as orig_currency_name',
            'currencies.code as orig_currency_code',
            'currencies.symbol as orig_currency_symbol',
            // Поля из таблицы пользователей
            'transactions.user_id as user_id',
            'users.name as user_name',
            // Поля из таблицы категорий
            'transactions.category_id as category_id',
            'transaction_categories.name as category_name',
            'transaction_categories.type as category_type',
            // Поля из таблицы проектов
            'transactions.project_id as project_id',
            'projects.name as project_name',
            // Поля из таблицы клиентов
            'transactions.client_id as client_id',
            // Поля из таблицы транзакций
            'transactions.note as note',
            'transactions.date as date',
            'transactions.updated_at as updated_at',
            'transactions.created_at as created_at',
        );
        $items = $query->get();

        $client_ids = $items->pluck('client_id')->toArray();
        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids);

        foreach ($items as $item) {
            $item->client = $clients->firstWhere('id', $item->client_id);
        }
        return $items;
    }
}
