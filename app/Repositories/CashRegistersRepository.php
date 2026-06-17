<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\CashRegisterUser;
use App\Models\Transaction;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class CashRegistersRepository extends BaseRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters)
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);

        return $this->getItemsWithPagination($perPage, $page);
    }

    /**
     * Получить кассы с пагинацией
     *
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($perPage = 20, $page = 1)
    {
        $query = CashRegister::with(['currency:id,name,code', 'creator:id,name,surname,photo', 'users:id,name']);

        $this->applyUserFilter($query);
        $query = $this->addCompanyFilterDirect($query, 'cash_registers');

        return $query->orderBy('cash_registers.sort_order')
            ->orderBy('cash_registers.id')
            ->paginate($perPage, ['*'], 'page', (int)$page);
    }

    /**
     * Получить все кассы пользователя
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems()
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('cash_registers_all', [$currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function() {
            $query = CashRegister::with(['currency:id,name,code', 'creator:id,name,surname,photo', 'users:id,name']);

            $this->applyUserFilter($query);
            $query = $this->addCompanyFilterDirect($query, 'cash_registers');

            return $query->orderBy('cash_registers.sort_order')
                ->orderBy('cash_registers.id')
                ->get();
        });
    }

    /**
     * Получить баланс касс
     *
     * @param array $cash_register_ids Массив ID касс
     * @param bool $all Получить все кассы
     * @param string|null $startDate Начальная дата
     * @param string|null $endDate Конечная дата
     * @param string|null $transactionType Тип транзакции ('income', 'outcome', 'transfer')
     * @param array|null $source Массив источников ('sale', 'order', 'other')
     * @return \Illuminate\Support\Collection
     */
    public function getCashBalance(
        $cash_register_ids = [],
        $all = false,
        $startDate = null,
        $endDate = null,
        $transactionType = null,
        $source = null
    ) {
        $query = CashRegister::with(['currency:id,name,code']);

        $this->applyUserFilter($query);
        $query = $this->addCompanyFilterDirect($query, 'cash_registers');

        if (!$all && !empty($cash_register_ids)) {
            $query->whereIn('cash_registers.id', $cash_register_ids);
        }

        $cashRegisters = $query->orderBy('cash_registers.sort_order')
            ->orderBy('cash_registers.id')
            ->get();

        if ($cashRegisters->isEmpty()) {
            return collect();
        }

        $cashRegisterIds = $cashRegisters->pluck('id');

        $transactionsQuery = Transaction::whereIn('cash_id', $cashRegisterIds)
            ->where('is_deleted', false);

        if ($startDate || $endDate) {
            $this->applyDateFilter($transactionsQuery, 'custom', $startDate, $endDate, 'date');
        }

        $transactionsQuery->when($transactionType, function ($q) use ($transactionType) {
            switch ($transactionType) {
                case 'income':
                    return $q->where('type', 1);
                case 'outcome':
                    return $q->where('type', 0);
                case 'transfer':
                    return $q->where(function ($subQ) {
                        $subQ->whereHas('cashTransfersFrom')
                            ->orWhereHas('cashTransfersTo');
                    });
                default:
                    return $q;
            }
        })
        ->when($source, function ($q) use ($source) {
            return $this->applySourceFilter($q, $source);
        });

        $transactionsRepository = app(\App\Repositories\TransactionsRepository::class);
        $transactionsQuery = $transactionsRepository->applySourceTypeFilter($transactionsQuery);

        $transactionsStats = $transactionsQuery
            ->select('cash_id')
            ->selectRaw('SUM(CASE WHEN type = 1 AND is_debt = 0 THEN amount ELSE 0 END) as income_total')
            ->selectRaw('SUM(CASE WHEN type = 0 AND is_debt = 0 THEN amount ELSE 0 END) as outcome_total')
            ->groupBy('cash_id')
            ->get()
            ->keyBy('cash_id');

        return $cashRegisters->map(function ($cashRegister) use ($transactionsStats) {
            $stats = $transactionsStats->get($cashRegister->id);

            $income = (float) ($stats->income_total ?? 0);
            $outcome = (float) ($stats->outcome_total ?? 0);

            $balance = [
                ['value' => $income, 'title' => 'Приход', 'type' => 'income'],
                ['value' => $outcome, 'title' => 'Расход', 'type' => 'outcome'],
                ['value' => $cashRegister->balance, 'title' => 'Итого', 'type' => 'default'],
            ];

            return [
                'id' => $cashRegister->id,
                'name' => $cashRegister->name,
                'currency_id' => $cashRegister->currency_id,
                'currency_code' => $cashRegister->currency ? $cashRegister->currency->code : null,
                'is_cash' => (bool) $cashRegister->is_cash,
                'icon' => $cashRegister->icon,
                'icon_size' => $cashRegister->icon_size,
                'color' => $cashRegister->color,
                'balance' => $balance,
            ];
        });
    }

    /**
     * Создать кассу
     *
     * @param array $data Данные кассы
     * @return bool
     * @throws \Exception
     */
    public function createItem($data)
    {
        return DB::transaction(function () use ($data) {
            $companyId = $this->getCurrentCompanyId();

            $item = new CashRegister();
            $item->name = $data['name'];
            $item->balance = $data['balance'];
            $item->currency_id = $data['currency_id'];
            $item->company_id = $companyId;
            $item->creator_id = auth('api')->id();
            $item->is_cash = (bool) ($data['is_cash'] ?? true);
            $item->is_working_minus = (bool) ($data['is_working_minus'] ?? false);
            $item->participates_in_transfers = (bool) ($data['participates_in_transfers'] ?? true);
            $item->sort_order = (int) $data['sort_order'];
            $item->icon = $data['icon'] ?? null;
            $item->icon_size = $data['icon_size'];
            $item->color = $data['color'] ?? null;
            $item->save();

            $this->syncUsers($item->id, $data['users'] ?? []);

            CacheService::invalidateCashRegistersCache();
            CacheService::invalidatePaginatedData('cash_registers_paginated', $companyId);

            return true;
        });
    }

    /**
     * Обновить кассу
     *
     * @param int $id ID кассы
     * @param array $data Данные для обновления:
     *   - name (string) Название кассы
     *   - users (array) Массив ID пользователей
     *   - balance (float|null) Баланс кассы (опционально)
     *   - currency_id (int|null) ID валюты (опционально)
     * @return bool
     * @throws \Exception
     */
    public function updateItem($id, $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $item = CashRegister::findOrFail($id);

            $item->fill(Arr::only($data, ['name', 'balance', 'currency_id', 'sort_order']));

            if (array_key_exists('is_cash', $data)) {
                $item->is_cash = (bool) $data['is_cash'];
            }

            if (array_key_exists('is_working_minus', $data)) {
                $item->is_working_minus = (bool) $data['is_working_minus'];
            }

            if (array_key_exists('participates_in_transfers', $data)) {
                $item->participates_in_transfers = (bool) $data['participates_in_transfers'];
            }

            if (array_key_exists('icon', $data)) {
                $item->icon = $data['icon'];
            }

            if (array_key_exists('icon_size', $data)) {
                $item->icon_size = $data['icon_size'];
            }

            if (array_key_exists('color', $data)) {
                $item->color = $data['color'];
            }

            $item->save();

            if (isset($data['users'])) {
                $this->syncUsers($id, $data['users']);
            }

            CacheService::invalidateCashRegistersCache();
            CacheService::invalidatePaginatedData('cash_registers_paginated', $this->getCurrentCompanyId());

            return true;
        });
    }

    /**
     * Удалить кассу
     *
     * @param int $id ID кассы
     * @return bool
     * @throws \Exception
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если касса не найдена
     */
    public function deleteItem($id)
    {
        return DB::transaction(function () use ($id) {
            $item = CashRegister::findOrFail($id);

            $transactionsCount = \App\Models\Transaction::where('cash_id', $id)
                ->where('is_deleted', false)
                ->count();

            if ($transactionsCount > 0) {
                throw new \Exception(__('api.cash_registers.delete_has_transactions'));
            }

            $transfersCount = \App\Models\CashTransfer::where(function($query) use ($id) {
                $query->where('cash_id_from', $id)
                      ->orWhere('cash_id_to', $id);
            })->count();

            if ($transfersCount > 0) {
                throw new \Exception(__('api.cash_registers.delete_has_transfers'));
            }

            CashRegisterUser::where('cash_register_id', $id)->delete();
            $item->delete();

            CacheService::invalidateCashRegistersCache();
            CacheService::invalidatePaginatedData('cash_registers_paginated', $this->getCurrentCompanyId());

            return true;
        });
    }

    /**
     * Синхронизировать пользователей кассы
     *
     * @param int $cashRegisterId ID кассы
     * @param array $userIds Массив ID пользователей
     * @return void
     * @throws \Exception Если пытаются удалить всех пользователей
     */
    private function syncUsers(int $cashRegisterId, array $userIds)
    {
        $userIds = $this->includeCurrentUserForNonAdmin($userIds);

        $this->syncManyToManyUsers(
            CashRegisterUser::class,
            'cash_register_id',
            $cashRegisterId,
            $userIds,
            [
                'require_at_least_one' => true,
                'error_message' => 'Касса должна иметь хотя бы одного пользователя',
                'user_column' => 'user_id',
            ]
        );
    }

    /**
     * Применить фильтр пользователя к запросу касс
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @return void
     */
    private function applyUserFilter($query)
    {
        if (!$this->shouldApplyUserFilter('cash_registers')) {
            return;
        }

        $currentUser = auth('api')->user();
        if (!$currentUser) {
            return;
        }

        $filterUserId = $currentUser->id;
        $cashRegisterIds = CashRegisterUser::where('user_id', $filterUserId)
            ->pluck('cash_register_id')
            ->toArray();

        if (empty($cashRegisterIds)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn('cash_registers.id', $cashRegisterIds);
    }

    /**
     * Применить фильтр по источникам транзакций
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param array|null $source Массив источников
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applySourceFilter($query, $source)
    {
        if (empty($source)) {
            return $query;
        }

        return $query->where(function ($subQ) use ($source) {
            $sourceMap = [
                'sale' => 'App\\Models\\Sale',
                'order' => 'App\\Models\\Order',
                'receipt' => 'App\\Models\\WhReceipt',
                'salary' => 'App\\Models\\EmployeeSalary',
            ];

            $hasConditions = false;

            foreach ($sourceMap as $key => $modelClass) {
                if (in_array($key, $source)) {
                    if ($hasConditions) {
                        $subQ->orWhere('source_type', $modelClass);
                    } else {
                        $subQ->where('source_type', $modelClass);
                        $hasConditions = true;
                    }
                }
            }

            if (in_array('other', $source)) {
                if ($hasConditions) {
                    $subQ->orWhere(function ($otherQ) use ($sourceMap) {
                        $otherQ->whereNull('source_type')
                            ->orWhereNotIn('source_type', array_values($sourceMap));
                    });
                } else {
                    $subQ->where(function ($otherQ) use ($sourceMap) {
                        $otherQ->whereNull('source_type')
                            ->orWhereNotIn('source_type', array_values($sourceMap));
                    });
                }
            }
        });
    }

}
