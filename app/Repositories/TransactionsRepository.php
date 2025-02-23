<?php

namespace App\Repositories;

use App\Models\Transaction;

class TransactionsRepository
{
    // // Получение с пагинацией
    // public function getItemsWithPagination($userUuid, $perPage = 20)
    // {
    //     $items = CashRegister::leftJoin('currencies as currencies', 'cash_registers.currency_id', '=', 'currencies.id')
    //         ->select('cash_registers.*', 'currencies.name as currency_name', 'currencies.code as currency_code', 'currencies.symbol as currency_symbol')
    //         ->whereJsonContains('cash_registers.users', (string) $userUuid)
    //         ->paginate($perPage);
    //     return $items;
    // }

    // Получение всего списка
    public function getAllItems($userUuid)
    {
        // $items = Transaction::leftJoin('currencies as currencies', 'cash_registers.currency_id', '=', 'currencies.id')
        //     ->select('cash_registers.*', 'currencies.name as currency_name', 'currencies.code as currency_code', 'currencies.symbol as currency_symbol')
        //     ->whereJsonContains('cash_registers.users', (string) $userUuid)
        //     ->get();
        $items = $this->getItems();
        return $items;
    }

    // // Создание
    // public function createItem($data)
    // {
    //     $item = new CashRegister();
    //     $item->name = $data['name'];
    //     $item->balance = $data['balance'];
    //     $item->currency_id = $data['currency_id'];
    //     $item->users = array_map('strval', $data['users']);
    //     $item->save();

    //     return true;
    // }

    // // Обновление
    // public function updateItem($id, $data)
    // {
    //     $item = CashRegister::find($id);
    //     $item->name = $data['name'];
    //     // $item->balance = $data['balance'];
    //     // $item->currency_id = $data['currency_id'];
    //     $item->users = array_map('strval', $data['users']);
    //     $item->save();

    //     return true;
    // }

    // // Удаление
    // public function deleteItem($id)
    // {
    //     $item = CashRegister::find($id);
    //     $item->delete();

    //     return true;
    // }


    private function getItems(array $ids = [])
    {
        // if (empty($ids)) {
        //     return Transaction::all();
        // }

        $query = Transaction::query();
        // присоединяем таблицу пользователей
        $query->leftJoin('users as users', 'transactions.user_id', '=', 'users.id');
        // Присоединяем таблицу валют
        $query->leftJoin('currencies as currencies', 'transactions.currency_id', '=', 'currencies.id');
        // Присоединяем таблицу касс
        $query->leftJoin('cash_registers as cash_registers', 'transactions.cash_id', '=', 'cash_registers.id');
        // Присоединяем таблицу категорий
        $query->leftJoin('transaction_categories as transaction_categories', 'transactions.category_id', '=', 'transaction_categories.id');
        // Присоединяем таблицу клиентов
        $query->leftJoin('clients as clients', 'transactions.client_id', '=', 'clients.id');
        // Выбираем поля
        $query->select(
            // Поля из таблицы транзакций
            'transactions.id as id',
            'transactions.type as type',
            'transactions.user_id as user_id', 
            // Поля из таблицы пользователей
            'users.name as user_name',
            // Поля из таблицы касс
            'transactions.cash_id as cash_id', 
            'cash_registers.name as cash_name',
            // Поля из таблицы валют
            'transactions.amount as amount', 
            'currencies.name as currency_name', 'currencies.code as currency_code', 'currencies.symbol as currency_symbol',
            // Поля из таблицы категорий
            'transactions.category_id as category_id',
            'transaction_categories.name as category_name',
            'transaction_categories.type as category_type',
            // Поля из таблицы клиентов
            'transactions.client_id as client_id',
            'clients.name as client_name',
            'clients.phone as client_phone',
        );
        $items = $query->get();
        return $items;
    }
}
