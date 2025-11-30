<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\CashTransfer;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class TransfersRepository extends BaseRepository
{
    /**
     * Получить перемещения с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('transfers_paginated', [$userUuid, $perPage, $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function() use ($userUuid, $perPage, $page, $currentUser) {
            $query = CashTransfer::query()
                ->with([
                    'fromCashRegister' => function ($q) {
                        $q->select('id', 'name', 'currency_id', 'company_id');
                    },
                    'fromCashRegister.currency' => function ($q) {
                        $q->select('id', 'name', 'code', 'symbol');
                    },
                    'toCashRegister' => function ($q) {
                        $q->select('id', 'name', 'currency_id', 'company_id');
                    },
                    'toCashRegister.currency' => function ($q) {
                        $q->select('id', 'name', 'code', 'symbol');
                    },
                    'user:id,name',
                ])
                ->select('cash_transfers.*')
                ->where(function ($q) use ($userUuid) {
                    if ($this->shouldApplyUserFilter('cash_registers')) {
                        $filterUserId = $this->getFilterUserIdForPermission('cash_registers', $userUuid);
                        $q->whereHas('fromCashRegister.cashRegisterUsers', function ($subQuery) use ($filterUserId) {
                            $subQuery->where('user_id', $filterUserId);
                        })->orWhereHas('toCashRegister.cashRegisterUsers', function ($subQuery) use ($filterUserId) {
                            $subQuery->where('user_id', $filterUserId);
                        });
                    }
                });

            $query = $this->addCompanyFilterThroughRelation($query, 'fromCashRegister');
            $query = $this->addCompanyFilterThroughRelation($query, 'toCashRegister');

            $items = $query
                ->orderByDesc('cash_transfers.id')
                ->paginate($perPage, ['*'], 'page', (int)$page);

            $items->getCollection()->transform(function ($transfer) {
                $fromCash = $transfer->fromCashRegister;
                $toCash = $transfer->toCashRegister;

                return (object)[
                    'id' => $transfer->id,
                    'cash_from_id' => $transfer->cash_id_from,
                    'cash_from_name' => $fromCash?->name,
                    'currency_from_id' => $fromCash?->currency?->id,
                    'currency_from_name' => $fromCash?->currency?->name,
                    'currency_from_code' => $fromCash?->currency?->code,
                    'currency_from_symbol' => $fromCash?->currency?->symbol,
                    'cash_to_id' => $transfer->cash_id_to,
                    'cash_to_name' => $toCash?->name,
                    'currency_to_id' => $toCash?->currency?->id,
                    'currency_to_name' => $toCash?->currency?->name,
                    'currency_to_code' => $toCash?->currency?->code,
                    'currency_to_symbol' => $toCash?->currency?->symbol,
                    'amount' => $transfer->amount,
                    'user_id' => $transfer->user?->id,
                    'user_name' => $transfer->user?->name,
                    'date' => $transfer->date,
                    'note' => $transfer->note,
                    'category_id' => 17,
                    'category_name' => 'Перемещение',
                ];
            });

            return $items;
        }, (int)$page);
    }

    /**
     * Создать перемещение между кассами
     *
     * @param array $data Данные перемещения
     * @return bool
     * @throws \Exception
     */
    public function createItem($data)
    {
        $cash_from_id = $data['cash_id_from'];
        $cash_to_id = $data['cash_id_to'];
        $amount = $data['amount'];
        $userUuid = $data['user_id'];
        $note = $data['note'];
        $date_of_transfer = $data['date'] ?? now();
        $fromCashRegister = CashRegister::findOrFail($cash_from_id);
        $toCashRegister = CashRegister::findOrFail($cash_to_id);

        if ($fromCashRegister->balance < $amount) {
            throw new \Exception('Недостаточно средств на кассе отправителя');
        }

        $fromCurrency = $fromCashRegister->currency;
        $toCurrency = $toCashRegister->currency;

        if ($fromCurrency->id !== $toCurrency->id) {
            if (isset($data['exchange_rate']) && $data['exchange_rate'] > 0) {
                $amountInTargetCurrency = $amount * $data['exchange_rate'];
            } else {
                $amountInTargetCurrency = CurrencyConverter::convert($amount, $fromCurrency, $toCurrency);
            }
        } else {
            $amountInTargetCurrency = $amount;
        }

        DB::beginTransaction();

        try {
            $transferNote = "Трансфер из кассы '{$fromCashRegister->name}' в кассу '{$toCashRegister->name}'.";

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

            $transaction_repository = new TransactionsRepository();
            $fromTransactionId = $transaction_repository->createItem($fromTransactionData, true);
            $toTransactionId = $transaction_repository->createItem($toTransactionData, true);

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

            DB::commit();

            $transaction_repository->invalidateTransactionsCache();
            CacheService::invalidateCashRegistersCache();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Обновить перемещение
     *
     * @param int $id ID перемещения
     * @param array $data Данные для обновления
     * @return bool
     * @throws \Exception
     */
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
            throw $e;
        }
    }

    /**
     * Удалить перемещение
     *
     * @param int $id ID перемещения
     * @return bool
     * @throws \Exception
     */
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

            app(TransactionsRepository::class)->invalidateTransactionsCache();
            CacheService::invalidateCashRegistersCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
