<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\CashRegisterUser;
use App\Models\Transaction;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class CahRegistersRepository extends BaseRepository
{
    /**
     * Получить кассы с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $query = CashRegister::with(['currency:id,name,symbol', 'users:id,name']);

        $this->applyUserFilter($query, $userUuid);
        $query = $this->addCompanyFilterDirect($query, 'cash_registers');

        return $query->orderBy('cash_registers.created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', (int)$page);
    }

    /**
     * Получить все кассы пользователя
     *
     * @param int $userUuid ID пользователя
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('cash_registers')) {
            throw new \Exception('Table cash_registers does not exist');
        }

        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('cash_registers_all', [$userUuid, $currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function() use ($userUuid) {
            $query = CashRegister::with(['currency:id,name,symbol', 'users:id,name']);

            $this->applyUserFilter($query, $userUuid);
            $query = $this->addCompanyFilterDirect($query, 'cash_registers');

            return $query->orderBy('cash_registers.id')->get();
        });
    }

    /**
     * Получить баланс касс
     *
     * @param int $userUuid ID пользователя
     * @param array $cash_register_ids Массив ID касс
     * @param bool $all Получить все кассы
     * @param string|null $startDate Начальная дата
     * @param string|null $endDate Конечная дата
     * @param string|null $transactionType Тип транзакции ('income', 'outcome', 'transfer')
     * @param array|null $source Массив источников ('sale', 'order', 'other')
     * @return \Illuminate\Support\Collection
     */
    public function getCashBalance(
        $userUuid,
        $cash_register_ids = [],
        $all = false,
        $startDate = null,
        $endDate = null,
        $transactionType = null,
        $source = null
    ) {
        $query = CashRegister::with(['currency:id,name,symbol']);

        $this->applyUserFilter($query, $userUuid);
        $query = $this->addCompanyFilterDirect($query, 'cash_registers');

        if (!$all && !empty($cash_register_ids)) {
            $query->whereIn('cash_registers.id', $cash_register_ids);
        }

        $cashRegisters = $query->get();

        if ($cashRegisters->isEmpty()) {
            return collect();
        }

        $cashRegisterIds = $cashRegisters->pluck('id');

        $transactionsQuery = Transaction::whereIn('cash_id', $cashRegisterIds)
            ->where('is_deleted', false)
            ->when($startDate || $endDate, function ($q) use ($startDate, $endDate) {
                if ($startDate && $endDate) {
                    // Парсим даты, поддерживая формат DD.MM.YYYY
                    try {
                        $parsedStart = \Carbon\Carbon::createFromFormat('d.m.Y', $startDate)->startOfDay();
                        $parsedEnd = \Carbon\Carbon::createFromFormat('d.m.Y', $endDate)->endOfDay();
                        return $q->whereBetween('date', [$parsedStart->toDateTimeString(), $parsedEnd->toDateTimeString()]);
                    } catch (\Exception $e) {
                        // Если не получилось распарсить как DD.MM.YYYY, пробуем стандартный parse
                        $parsedStart = \Carbon\Carbon::parse($startDate)->startOfDay();
                        $parsedEnd = \Carbon\Carbon::parse($endDate)->endOfDay();
                        return $q->whereBetween('date', [$parsedStart->toDateTimeString(), $parsedEnd->toDateTimeString()]);
                    }
                } elseif ($startDate) {
                    try {
                        $parsedStart = \Carbon\Carbon::createFromFormat('d.m.Y', $startDate)->startOfDay();
                        return $q->where('date', '>=', $parsedStart->toDateTimeString());
                    } catch (\Exception $e) {
                        $parsedStart = \Carbon\Carbon::parse($startDate)->startOfDay();
                        return $q->where('date', '>=', $parsedStart->toDateTimeString());
                    }
                } elseif ($endDate) {
                    try {
                        $parsedEnd = \Carbon\Carbon::createFromFormat('d.m.Y', $endDate)->endOfDay();
                        return $q->where('date', '<=', $parsedEnd->toDateTimeString());
                    } catch (\Exception $e) {
                        $parsedEnd = \Carbon\Carbon::parse($endDate)->endOfDay();
                        return $q->where('date', '<=', $parsedEnd->toDateTimeString());
                    }
                }
                return $q;
            })
            ->when($transactionType, function ($q) use ($transactionType) {
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
                if (empty($source)) {
                    return $q;
                }

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
                    if (in_array('receipt', $source)) {
                        if ($hasConditions) {
                            $subQ->orWhere('source_type', 'App\\Models\\WhReceipt');
                        } else {
                            $subQ->where('source_type', 'App\\Models\\WhReceipt');
                        }
                        $hasConditions = true;
                    }
                    if (in_array('salary', $source)) {
                        if ($hasConditions) {
                            $subQ->orWhere('source_type', 'App\\Models\\EmployeeSalary');
                        } else {
                            $subQ->where('source_type', 'App\\Models\\EmployeeSalary');
                        }
                        $hasConditions = true;
                    }
                    if (in_array('other', $source)) {
                        if ($hasConditions) {
                            $subQ->orWhere(function ($otherQ) {
                                $otherQ->whereNull('source_type')
                                    ->orWhereNotIn('source_type', [
                                        'App\\Models\\Sale',
                                        'App\\Models\\Order',
                                        'App\\Models\\WhReceipt',
                                        'App\\Models\\EmployeeSalary'
                                    ]);
                            });
                        } else {
                            $subQ->whereNull('source_type')
                                ->orWhereNotIn('source_type', [
                                    'App\\Models\\Sale',
                                    'App\\Models\\Order',
                                    'App\\Models\\WhReceipt',
                                    'App\\Models\\EmployeeSalary'
                                ]);
                        }
                    }
                });
            });

        $transactionsRepository = app(\App\Repositories\TransactionsRepository::class);
        $transactionsQuery = $transactionsRepository->applySourceTypeFilter($transactionsQuery);

        $transactionsStats = $transactionsQuery
            ->select('cash_id')
            ->selectRaw('SUM(CASE WHEN type = 1 AND is_debt = 0 THEN amount ELSE 0 END) as income_total')
            ->selectRaw('SUM(CASE WHEN type = 0 AND is_debt = 0 THEN amount ELSE 0 END) as outcome_total')
            ->selectRaw('SUM(CASE WHEN type = 1 AND is_debt = 1 THEN amount ELSE 0 END) as debt_income_total')
            ->selectRaw('SUM(CASE WHEN type = 0 AND is_debt = 1 THEN amount ELSE 0 END) as debt_outcome_total')
            ->groupBy('cash_id')
            ->get()
            ->keyBy('cash_id');

        return $cashRegisters->map(function ($cashRegister) use ($transactionsStats) {
            $stats = $transactionsStats->get($cashRegister->id);

            $income = (float) ($stats->income_total ?? 0);
            $outcome = (float) ($stats->outcome_total ?? 0);
            $debtIncome = (float) ($stats->debt_income_total ?? 0);
            $debtOutcome = (float) ($stats->debt_outcome_total ?? 0);
            $debtTotal = $debtIncome - $debtOutcome;

            $balance = [
                ['value' => $income, 'title' => 'Приход', 'type' => 'income'],
                ['value' => $outcome, 'title' => 'Расход', 'type' => 'outcome'],
                ['value' => $cashRegister->balance, 'title' => 'Итого', 'type' => 'default'],
            ];

            if ($debtTotal != 0) {
                $balance[] = ['value' => $debtTotal, 'title' => 'Долг', 'type' => 'debt'];
            }

            return [
                'id' => $cashRegister->id,
                'name' => $cashRegister->name,
                'currency_id' => $cashRegister->currency_id,
                'currency_symbol' => $cashRegister->currency ? $cashRegister->currency->symbol : null,
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
            $item = new CashRegister();
            $item->name = $data['name'];
            $item->balance = $data['balance'];
            $item->currency_id = $data['currency_id'];
            $item->company_id = $this->getCurrentCompanyId();
            $item->save();

            $this->syncUsers($item->id, $data['users'] ?? []);

            CacheService::invalidateCashRegistersCache();

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

            if (isset($data['name'])) {
                $item->name = $data['name'];
            }
            if (isset($data['balance'])) {
                $item->balance = $data['balance'];
            }
            if (isset($data['currency_id'])) {
                $item->currency_id = $data['currency_id'];
            }

            $item->save();

            if (isset($data['users'])) {
                $this->syncUsers($id, $data['users']);
            }

            CacheService::invalidateCashRegistersCache();

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
            $item->delete();

            CashRegisterUser::where('cash_register_id', $id)->delete();

            CacheService::invalidateCashRegistersCache();

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
        $this->syncManyToManyUsers(
            CashRegisterUser::class,
            'cash_register_id',
            $cashRegisterId,
            $userIds,
            [
                'require_at_least_one' => true,
                'error_message' => 'Касса должна иметь хотя бы одного пользователя'
            ]
        );
    }

    /**
     * Применить фильтр пользователя к запросу касс
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param int $userUuid ID пользователя
     * @return void
     */
    private function applyUserFilter($query, $userUuid)
    {
        if (!$this->shouldApplyUserFilter('cash_registers')) {
            return;
        }

        $filterUserId = $this->getFilterUserIdForPermission('cash_registers', $userUuid);
        $query->join('cash_register_users', 'cash_registers.id', '=', 'cash_register_users.cash_register_id')
            ->where('cash_register_users.user_id', $filterUserId)
            ->select('cash_registers.*')
            ->distinct();
    }

}
