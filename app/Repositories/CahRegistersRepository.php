<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\CashRegisterUser;
use App\Models\Transaction;
use App\Services\CacheService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CahRegistersRepository
{
    /**
     * Получить текущую компанию пользователя из заголовка запроса
     */
    private function getCurrentCompanyId()
    {
        // Получаем company_id из заголовка запроса
        return request()->header('X-Company-ID');
    }

    /**
     * Добавить фильтрацию по компании к запросу
     */
    private function addCompanyFilter($query)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->where('cash_registers.company_id', $companyId);
        } else {
            // Если компания не выбрана, показываем только кассы без company_id (для обратной совместимости)
            $query->whereNull('cash_registers.company_id');
        }
        return $query;
    }

    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        try {
            // Возвращаем только кассы, к которым у пользователя есть доступ
            $query = CashRegister::with(['currency:id,name,code,symbol', 'users:id,name'])
                ->whereHas('cashRegisterUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                });

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            return $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
        } catch (\Exception $e) {
            // Возвращаем пустую пагинацию вместо ошибки
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }
    }

    // Получение всего списка
    public function getAllItems($userUuid)
    {
        try {
            // Проверяем, существует ли таблица
            if (!\Illuminate\Support\Facades\Schema::hasTable('cash_registers')) {
                throw new \Exception('Table cash_registers does not exist');
            }

            // Возвращаем только кассы, к которым у пользователя есть доступ
            $query = CashRegister::with(['currency:id,name,code,symbol', 'users:id,name'])
                ->whereHas('cashRegisterUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                });

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            return $query->get();
        } catch (\Exception $e) {
            // Возвращаем пустую коллекцию вместо ошибки
            return \Illuminate\Support\Collection::make();
        }
    }

    // Получение баланса касс
    public function getCashBalance(
        $userUuid,
        $cash_register_ids = [],
        $all = false,
        $startDate = null,
        $endDate = null,
        $transactionType = null,
        $source = null
    ) {
        $query = CashRegister::with(['currency:id,name,code,symbol'])
            ->whereHas('cashRegisterUsers', function($q) use ($userUuid) {
                $q->where('user_id', $userUuid);
            });

        // Применяем фильтр по конкретным кассам
        if (!$all && !empty($cash_register_ids)) {
            $query->whereIn('id', $cash_register_ids);
        }

        $items = $query->get()
            ->map(function ($cashRegister) use ($userUuid, $startDate, $endDate, $transactionType, $source) {

                // базовый запрос по транзакциям
                $txBase = Transaction::where('cash_id', $cashRegister->id)
                    ->when($startDate && $endDate, function ($q) use ($startDate, $endDate) {
                        return $q->whereBetween('created_at', [$startDate, $endDate]);
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
                        if (empty($source)) return $q;

                        return $q->where(function ($subQ) use ($source) {
                            $hasConditions = false;

                            if (in_array('project', $source)) {
                                $subQ->whereNotNull('project_id');
                                $hasConditions = true;
                            }
                            if (in_array('sale', $source)) {
                                if ($hasConditions) {
                                    $subQ->orWhereHas('sales');
                                } else {
                                    $subQ->whereHas('sales');
                                }
                                $hasConditions = true;
                            }
                            if (in_array('order', $source)) {
                                if ($hasConditions) {
                                    $subQ->orWhereHas('orders');
                                } else {
                                    $subQ->whereHas('orders');
                                }
                                $hasConditions = true;
                            }
                            if (in_array('other', $source)) {
                                if ($hasConditions) {
                                    $subQ->orWhere(function ($otherQ) {
                                        $otherQ->whereNull('project_id')
                                            ->whereDoesntHave('sales')
                                            ->whereDoesntHave('orders');
                                    });
                                } else {
                                    $subQ->whereNull('project_id')
                                        ->whereDoesntHave('sales')
                                        ->whereDoesntHave('orders');
                                }
                            }
                        });
                    });

                $income  = (clone $txBase)->where('type', 1)->sum('amount');
                $outcome = (clone $txBase)->where('type', 0)->sum('amount');

                // Логируем результаты для отладки
                Log::info('Balance calculation result', [
                    'cash_register_id' => $cashRegister->id,
                    'income' => $income,
                    'outcome' => $outcome,
                    'calculated_total' => $income - $outcome,
                    'stored_balance' => $cashRegister->balance
                ]);

                return [
                    'id'          => $cashRegister->id,
                    'name'        => $cashRegister->name,
                    'currency_id' => $cashRegister->currency_id,
                    'currency_symbol' => $cashRegister->currency ? $cashRegister->currency->symbol : null,
                    'currency_code' => $cashRegister->currency ? $cashRegister->currency->code : null,
                    'balance'     => [
                        ['value' => $income,  'title' => 'Приход',  'type' => 'income'],
                        ['value' => $outcome, 'title' => 'Расход',  'type' => 'outcome'],
                        ['value' => $cashRegister->balance, 'title' => 'Итого', 'type' => 'default'],
                    ],
                ];
            });
        return $items;
    }

    // Создание
    public function createItem($data)
    {
        DB::beginTransaction();
        try {
            $item = new CashRegister();
            $item->name = $data['name'];
            $item->balance = $data['balance'];
            $item->is_rounding = $data['is_rounding'] ?? false;
            $item->currency_id = $data['currency_id'];
            $item->company_id = $this->getCurrentCompanyId();
            $item->save();

            // Создаем связи с пользователями
            foreach ($data['users'] as $userId) {
                CashRegisterUser::create([
                    'cash_register_id' => $item->id,
                    'user_id' => $userId
                ]);
            }

            DB::commit();

            // Инвалидируем кэш касс
            $this->invalidateCashRegistersCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // Обновление
    public function updateItem($id, $data)
    {
        DB::beginTransaction();
        try {
            $item = CashRegister::find($id);
            $item->name = $data['name'];
            $item->is_rounding = $data['is_rounding'] ?? false;
            // $item->balance = $data['balance'];
            // $item->currency_id = $data['currency_id'];
            $item->save();

            // Удаляем старые связи
            CashRegisterUser::where('cash_register_id', $id)->delete();

            // Создаем новые связи
            foreach ($data['users'] as $userId) {
                CashRegisterUser::create([
                    'cash_register_id' => $id,
                    'user_id' => $userId
                ]);
            }

            DB::commit();

            // Инвалидируем кэш касс
            $this->invalidateCashRegistersCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // Удаление
    public function deleteItem($id)
    {
        DB::beginTransaction();
        try {
            $item = CashRegister::find($id);
            $item->delete();

            // Удаляем связи с пользователями
            CashRegisterUser::where('cash_register_id', $id)->delete();

            DB::commit();

            // Инвалидируем кэш касс
            $this->invalidateCashRegistersCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // Быстрый поиск касс
    public function fastSearch($search, $perPage = 20)
    {
        try {
            // Возвращаем результаты поиска с валютой
            return CashRegister::with(['currency:id,name,code,symbol'])
                ->where('name', 'like', "%{$search}%")
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
        } catch (\Exception $e) {
            // Возвращаем пустую пагинацию вместо ошибки
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }
    }

    // Получение кассы по ID
    public function findItem($id)
    {
        try {
            // Возвращаем кассу с валютой и пользователями
            return CashRegister::with(['currency:id,name,code,symbol', 'users:id,name'])->find($id);
        } catch (\Exception $e) {
            // Возвращаем null вместо ошибки
            return null;
        }
    }

    // Получение активных касс
    public function getActiveCashRegisters($userUuid)
    {
        try {
            // Возвращаем только активные кассы, к которым у пользователя есть доступ
            return CashRegister::with(['currency:id,code,symbol'])
                ->where('is_active', true)
                ->whereHas('cashRegisterUsers', function ($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                })
                ->get();
        } catch (\Exception $e) {
            // Возвращаем пустую коллекцию вместо ошибки
            return \Illuminate\Support\Collection::make();
        }
    }

    // Инвалидация кэша касс
    private function invalidateCashRegistersCache()
    {
        // Очищаем кэш касс
        $keys = [
            'cash_registers_paginated_*',
            'cash_registers_all_*',
            'cash_registers_fast_search_*',
            'cash_registers_active_*'
        ];

        foreach ($keys as $key) {
            if (str_contains($key, '*')) {
                // Для паттернов с wildcard очищаем весь кэш
                \Illuminate\Support\Facades\Cache::flush();
                break;
            }
        }
    }
}
