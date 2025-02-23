<?php

namespace App\Repositories;

use App\Models\CashRegister;

class CahRegistersRepository
{
    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $items = CashRegister::leftJoin('currencies as currencies', 'cash_registers.currency_id', '=', 'currencies.id')
            ->select('cash_registers.*', 'currencies.name as currency_name', 'currencies.code as currency_code', 'currencies.symbol as currency_symbol')
            ->whereJsonContains('cash_registers.users', (string) $userUuid)
            ->paginate($perPage);
        return $items;
    }

    // Получение всего списка
    public function getAllItems($userUuid)
    {
        $items = CashRegister::leftJoin('currencies as currencies', 'cash_registers.currency_id', '=', 'currencies.id')
            ->select('cash_registers.*', 'currencies.name as currency_name', 'currencies.code as currency_code', 'currencies.symbol as currency_symbol')
            ->whereJsonContains('cash_registers.users', (string) $userUuid)
            ->get();
        return $items;
    }

    // Создание
    public function createItem($data)
    {
        $item = new CashRegister();
        $item->name = $data['name'];
        $item->balance = $data['balance'];
        $item->currency_id = $data['currency_id'];
        $item->users = array_map('strval', $data['users']);
        $item->save();

        return true;
    }

    // Обновление
    public function updateItem($id, $data)
    {
        $item = CashRegister::find($id);
        $item->name = $data['name'];
        // $item->balance = $data['balance'];
        // $item->currency_id = $data['currency_id'];
        $item->users = array_map('strval', $data['users']);
        $item->save();

        return true;
    }

    // Удаление
    public function deleteItem($id)
    {
        $item = CashRegister::find($id);
        $item->delete();

        return true;
    }
}
