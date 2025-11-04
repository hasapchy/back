<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\CashTransfer;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class TransfersRepository
{

    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $companyId = request()->header('X-Company-ID');
        $cacheKey = "transfers_paginated_{$userUuid}_{$perPage}_{$companyId}";

        return CacheService::getPaginatedData($cacheKey, function() use ($userUuid, $perPage, $page) {
            return DB::table('cash_transfers')
            // присоединяем таблицу касс
            ->leftJoin('cash_registers as cash_from', 'cash_transfers.cash_id_from', '=', 'cash_from.id')
            ->leftJoin('cash_registers as cash_to', 'cash_transfers.cash_id_to', '=', 'cash_to.id')
            // присоединяем таблицу валют
            ->leftJoin('currencies as currency_from', 'cash_from.currency_id', '=', 'currency_from.id')
            ->leftJoin('currencies as currency_to', 'cash_to.currency_id', '=', 'currency_to.id')
            // присоединяем таблицу пользователей
            ->leftJoin('users as users', 'cash_transfers.user_id', '=', 'users.id')
            ->select(
                'cash_transfers.id as id',
                // касса отправитель
                'cash_from.id as cash_from_id',
                'cash_from.name as cash_from_name',
                // валюта отправителя
                'currency_from.id as currency_from_id',
                'currency_from.name as currency_from_name',
                'currency_from.code as currency_from_code',
                'currency_from.symbol as currency_from_symbol',
                // касса получатель
                'cash_to.id as cash_to_id',
                'cash_to.name as cash_to_name',
                // валюта получателя
                'currency_to.id as currency_to_id',
                'currency_to.name as currency_to_name',
                'currency_to.code as currency_to_code',
                'currency_to.symbol as currency_to_symbol',
                // сумма отправителя
                'cash_transfers.amount as amount',
                // пользователь
                'users.id as user_id',
                'users.name as user_name',
                // дата
                'cash_transfers.date as date',
                // заметка
                'cash_transfers.note as note',

            )
                ->paginate($perPage, ['*'], 'page', (int)$page);

            // Добавляем информацию о категории "Перемещение" к каждому элементу
            foreach ($items->items() as $item) {
                $item->category_id = 17;
                $item->category_name = 'Перемещение';
            }

            return $items;
        }, (int)$page);
    }

    // // Получение всего списка
    // public function getAllItems($userUuid)
    // {
    //     $items = Project::leftJoin('users as users', 'projects.user_id', '=', 'users.id')
    //         ->select('projects.*', 'users.name as user_name')
    //         ->whereJsonContains('projects.users', (string) $userUuid)
    //         ->get();
    //     $client_ids = $items->pluck('client_id')->toArray();

    //     $client_repository = new ClientsRepository();
    //     $clients = $client_repository->getItemsByIds($client_ids);

    //     foreach ($items as $item) {
    //         $item->client = $clients->firstWhere('id', $item->client_id);
    //     }
    //     return $items;
    // }

    // Создание
    public function createItem($data)
    {
        $cash_from_id = $data['cash_id_from'];
        $cash_to_id = $data['cash_id_to'];
        $amount = $data['amount'];
        $userUuid = $data['user_id'];
        $note = $data['note'];
        $date_of_transfer = now();
        $fromCashRegister = CashRegister::find($cash_from_id);
        $toCashRegister = CashRegister::find($cash_to_id);

        if (!$fromCashRegister || !$toCashRegister) {
            throw new \Exception('Одна из касс не найдена');
        }

        // Проверка достаточности средств
        if ($fromCashRegister->balance < $amount) {
            throw new \Exception('Недостаточно средств на кассе отправителя');
        }

        $fromCurrency = $fromCashRegister->currency;
        $toCurrency = $toCashRegister->currency;

        // Если валюты касс отличаются, конвертируем сумму
        if ($fromCurrency->id !== $toCurrency->id) {
            // Если передан курс вручную, используем его
            if (isset($data['exchange_rate']) && $data['exchange_rate'] > 0) {
                $amountInTargetCurrency = $amount * $data['exchange_rate'];
            } else {
                // Иначе используем автоматический расчет
                $amountInTargetCurrency = CurrencyConverter::convert($amount, $fromCurrency, $toCurrency);
            }
        } else {
            $amountInTargetCurrency = $amount;
        }

        // Начинаем транзакцию
        DB::beginTransaction();

        try {
            $transferNote = "Трансфер из кассы '{$fromCashRegister->name}' в кассу '{$toCashRegister->name}'.";

            // Данные для транзакции отправителя
            $fromTransactionData = [
                'type' => '0', // Расход
                'user_id' => $userUuid,
                'orig_amount' => $amount,
                'currency_id' => $fromCashRegister->currency_id,
                'cash_id' => $fromCashRegister->id,
                'category_id' => 17,
                'project_id' => null,
                'client_id' => null,
                'note' => $note . ' ' . $transferNote,
                'date' => $date_of_transfer
            ];

            // Данные для транзакции получателя
            $toTransactionData = [
                'type' => '1', // Приход
                'user_id' => $userUuid,
                'orig_amount' => $amountInTargetCurrency,
                'currency_id' => $toCashRegister->currency_id,
                'cash_id' => $toCashRegister->id,
                'category_id' => 17,
                'project_id' => null,
                'client_id' => null,
                'note' => $note . ' ' . $transferNote,
                'date' => $date_of_transfer
            ];

            // Создаем транзакции
            $transaction_repository = new TransactionsRepository();
            $fromTransactionId = $transaction_repository->createItem($fromTransactionData, true);
            $toTransactionId = $transaction_repository->createItem($toTransactionData, true);

            // Создаем запись трансфера
            CashTransfer::create([
                'cash_id_from' => $fromCashRegister->id,
                'cash_id_to' => $toCashRegister->id,
                'tr_id_from' => $fromTransactionId,
                'tr_id_to' => $toTransactionId,
                'user_id' => $userUuid,
                'amount' => $amount,
                'note' => $note,
                'date' => $date_of_transfer,
            ]);

            // Фиксируем транзакцию
            DB::commit();

            // Очищаем кэш транзакций и касс после создания трансфера
            $transaction_repository->invalidateTransactionsCache();
            \App\Services\CacheService::invalidateCashRegistersCache();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return true;
    }

    public function updateItem($id, $data)
    {
        DB::beginTransaction();
        try {
            $this->deleteItem($id);
            $this->createItem($data);
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }


    public function deleteItem($id)
    {
        DB::beginTransaction();
        try {
            $transfer = CashTransfer::findOrFail($id);

            $fromTransactionId = $transfer->tr_id_from;
            $toTransactionId = $transfer->tr_id_to;

            $transfer->delete();
            app(TransactionsRepository::class)->deleteItem($fromTransactionId);
            app(TransactionsRepository::class)->deleteItem($toTransactionId);

            DB::commit();

            // Очищаем кэш транзакций и касс после удаления трансфера
            app(TransactionsRepository::class)->invalidateTransactionsCache();
            \App\Services\CacheService::invalidateCashRegistersCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }
}
