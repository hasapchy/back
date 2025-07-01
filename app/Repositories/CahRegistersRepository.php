<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Transaction;

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

    // Получение баланса касс
    public function getCashBalance(
        $userUuid,
        $cash_register_ids = [],
        $all = false,
        $startDate = null,
        $endDate   = null
    ) {
        $items = CashRegister::when(!$all, function ($query) use ($cash_register_ids) {
            return $query->whereIn('id', $cash_register_ids);
        })
            ->whereJsonContains('cash_registers.users', (string) $userUuid)
            ->get()
            ->map(function ($cashRegister) use ($startDate, $endDate) {
                // базовый запрос по транзакциям
                $txBase = Transaction::where('cash_id', $cashRegister->id)
                    ->when($startDate && $endDate, function ($q) use ($startDate, $endDate) {
                        return $q->whereBetween('created_at', [$startDate, $endDate]);
                    });

                $income  = (clone $txBase)->where('type', 1)->sum('amount');
                $outcome = (clone $txBase)->where('type', 0)->sum('amount');

                return [
                    'id'          => $cashRegister->id,
                    'name'        => $cashRegister->name,
                    'currency_id' => $cashRegister->currency_id,
                    'balance'     => [
                        ['value' => $income,  'title' => 'Приход',  'type' => 'income'],
                        ['value' => $outcome, 'title' => 'Расход',  'type' => 'outcome'],
                        ['value' => $income - $outcome,       'title' => 'Итого', 'type' => 'default'],
                    ],
                ];
            });
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
