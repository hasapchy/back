<?php

namespace App\Repositories;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class ProjectsRepository
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
            $query->where('projects.company_id', $companyId);
        } else {
            // Если компания не выбрана, показываем только проекты без company_id (для обратной совместимости)
            $query->whereNull('projects.company_id');
        }
        return $query;
    }

    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusId = null, $clientId = null, $contractType = null)
    {
        // Создаем уникальный ключ кэша с учетом компании
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "projects_paginated_{$userUuid}_{$perPage}_{$search}_{$dateFilter}_{$startDate}_{$endDate}_{$statusId}_{$clientId}_{$contractType}_{$companyId}";

        // Для списка без фильтров используем более длительное кэширование
        $ttl = (!$search && $dateFilter === 'all_time' && !$statusId && !$clientId && $contractType === null) ? 1800 : 600; // 30 мин для списка, 10 мин для фильтров

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $page, $statusId, $clientId, $contractType) {
            // Оптимизированный запрос с селективным выбором полей и JOIN для клиентов
            $query = Project::select([
                'projects.id',
                'projects.name',
                'projects.budget',
                'projects.currency_id',
                'projects.exchange_rate',
                'projects.date',
                'projects.user_id',
                'projects.client_id',
                'projects.status_id',
                'projects.files',
                'projects.created_at',
                'projects.updated_at',
                'clients.first_name as client_first_name',
                'clients.last_name as client_last_name',
                'clients.contact_person as client_contact_person',
                'users.name as user_name',
                'users.photo as user_photo',
                'clients.balance as client_balance'
            ])
                ->leftJoin('clients', 'projects.client_id', '=', 'clients.id')
                ->leftJoin('users', 'projects.user_id', '=', 'users.id')
                ->with([
                    'client:id,first_name,last_name,contact_person',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'currency:id,name,code,symbol',
                    'status:id,name,color',
                    'creator:id,name',
                    'users:id,name',
                    'projectUsers:id,project_id,user_id'
                ]);

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            // Применяем фильтры
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('projects.id', 'like', "%{$search}%")
                        ->orWhere('projects.name', 'like', "%{$search}%")
                        ->orWhere('clients.first_name', 'like', "%{$search}%")
                        ->orWhere('clients.last_name', 'like', "%{$search}%")
                        ->orWhere('clients.contact_person', 'like', "%{$search}%");
                });
            }

            // Фильтрация по дате
            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter, $startDate, $endDate);
            }

            // Фильтрация по статусу
            if ($statusId) {
                $query->where('projects.status_id', $statusId);
            }

            // Фильтрация по клиенту
            if ($clientId) {
                $query->where('projects.client_id', $clientId);
            }

            // Фильтр по пользователю
            $query->whereHas('projectUsers', function ($query) use ($userUuid) {
                $query->where('user_id', $userUuid);
            });

            // Получаем результат с пагинацией
            return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }


    private function applyDateFilter($query, $dateFilter, $startDate, $endDate)
    {
        if ($dateFilter === 'today') {
            $query->whereBetween('projects.date', [
                now()->startOfDay()->toDateTimeString(),
                now()->endOfDay()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'yesterday') {
            $query->whereBetween('projects.date', [
                now()->subDay()->startOfDay()->toDateTimeString(),
                now()->subDay()->endOfDay()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'this_week') {
            $query->whereBetween('projects.date', [
                now()->startOfWeek()->toDateTimeString(),
                now()->endOfWeek()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'this_month') {
            $query->whereBetween('projects.date', [
                now()->startOfMonth()->toDateTimeString(),
                now()->endOfMonth()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'this_year') {
            $query->whereBetween('projects.date', [
                now()->startOfYear()->toDateTimeString(),
                now()->endOfYear()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'last_week') {
            $query->whereBetween('projects.date', [
                now()->subWeek()->startOfWeek()->toDateTimeString(),
                now()->subWeek()->endOfWeek()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'last_month') {
            $query->whereBetween('projects.date', [
                now()->subMonth()->startOfMonth()->toDateTimeString(),
                now()->subMonth()->endOfMonth()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'last_year') {
            $query->whereBetween('projects.date', [
                now()->subYear()->startOfYear()->toDateTimeString(),
                now()->subYear()->endOfYear()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'custom') {
            if ($startDate && $endDate) {
                $query->whereBetween('projects.date', [$startDate, $endDate]);
            }
        }
    }

    // Получение всего списка (включая все статусы для страницы проектов)
    public function getAllItems($userUuid, $activeOnly = false)
    {
        // Создаем уникальный ключ кэша с учетом компании
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "projects_all_{$userUuid}_{$activeOnly}_{$companyId}";

        return CacheService::remember($cacheKey, function () use ($userUuid, $activeOnly) {
            $query = Project::select([
                'projects.id',
                'projects.name',
                'projects.budget',
                'projects.currency_id',
                'projects.exchange_rate',
                'projects.date',
                'projects.user_id',
                'projects.client_id',
                'projects.status_id',
                'projects.files',
                'projects.created_at',
                'projects.updated_at',
                'clients.first_name as client_first_name',
                'clients.last_name as client_last_name',
                'users.name as user_name',
                'users.photo as user_photo',
                'clients.balance as client_balance'
            ])
                ->leftJoin('clients', 'projects.client_id', '=', 'clients.id')
                ->leftJoin('users', 'projects.user_id', '=', 'users.id')
                ->with([
                    'client:id,first_name,last_name,contact_person',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'currency:id,name,code,symbol',
                    'status:id,name,color',
                    'creator:id,name',
                    'users:id,name'
                ]);

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            // Фильтрация по активным статусам (исключаем "Завершен" и "Отменен")
            if ($activeOnly) {
                $query->whereNotIn('projects.status_id', [3, 4]);
            }

            return $query->whereHas('projectUsers', function ($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                })
                ->orderBy('created_at', 'desc')
                ->get();
        }, 1800); // 30 минут
    }

    // Создание
    public function createItem($data)
    {
        DB::beginTransaction();
        try {
            $companyId = $this->getCurrentCompanyId();



            $item = new Project();
            $item->name = $data['name'];
            $item->budget = $data['budget'] ?? 0;
            $item->currency_id = $data['currency_id'] ?? null;
            $item->exchange_rate = $data['exchange_rate'] ?? null;
            $item->date = $data['date'];
            $item->user_id = $data['user_id'];
            $item->client_id = $data['client_id'];
            $item->company_id = $companyId;
            $item->description = $data['description'] ?? null;
            $item->files = $data['files'] ?? [];
            $item->status_id = $data['status_id'] ?? 1; // Статус "Новый" по умолчанию
            $item->save();



            // Создаем связи с пользователями
            foreach ($data['users'] as $userId) {
                ProjectUser::create([
                    'project_id' => $item->id,
                    'user_id' => $userId
                ]);

            }

            DB::commit();


            // Инвалидируем кэш проектов
            $this->invalidateProjectsCache();

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
            $item = Project::find($id);
            if (!$item) {
                throw new \Exception('Project not found');
            }

            // Защита: если files переданы, убедись, что это массив с нужной структурой
            if (isset($data['files']) && is_array($data['files'])) {
                $item->files = $data['files'];
            }

            $item->name = $data['name'];
            $item->budget = $data['budget'] ?? $item->budget;
            $item->currency_id = $data['currency_id'] ?? $item->currency_id;
            $item->exchange_rate = $data['exchange_rate'] ?? $item->exchange_rate;
            $item->date = $data['date'];
            $item->user_id = $data['user_id'];
            $item->client_id = $data['client_id'];
            $item->description = $data['description'] ?? null;
            $item->status_id = $data['status_id'] ?? $item->status_id;

            $item->save();

            // Удаляем старые связи
            ProjectUser::where('project_id', $id)->delete();

            // Создаем новые связи
            foreach ($data['users'] as $userId) {
                ProjectUser::create([
                    'project_id' => $id,
                    'user_id' => $userId
                ]);
            }

            DB::commit();

            // Инвалидируем кэш проектов
            $this->invalidateProjectsCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function findItemWithRelations($id, $userUuid = null)
    {
        $cacheKey = "project_item_relations_{$id}_{$userUuid}";

        return CacheService::remember($cacheKey, function () use ($id, $userUuid) {
            $query = Project::select([
                'projects.id',
                'projects.name',
                'projects.budget',
                'projects.currency_id',
                'projects.exchange_rate',
                'projects.date',
                'projects.user_id',
                'projects.client_id',
                'projects.files',
                'projects.created_at',
                'projects.updated_at'
            ])
                ->with([
                    'client:id,first_name,last_name,contact_person,balance',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',

                    'currency:id,name,code,symbol',
                    'users:id,name',
                    'projectUsers:id,project_id,user_id'
                ])
                ->where('id', $id);

            if ($userUuid) {
                $query->whereHas('projectUsers', function ($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                });
            }

            $result = $query->first();


            return $result;
        }, 1800); // 30 минут
    }

    // Удаление
    public function deleteItem($id)
    {
        DB::beginTransaction();
        try {
            $item = Project::find($id);
            if (!$item) {
                return false;
            }

            // Проверяем, есть ли привязанные транзакции
            $transactionsCount = \App\Models\Transaction::where('project_id', $id)
                ->where('is_deleted', false)
                ->count();
            if ($transactionsCount > 0) {
                throw new \Exception('Невозможно удалить проект, к нему привязано транзакций: ' . $transactionsCount);
            }

            // Удаляем связи с пользователями
            ProjectUser::where('project_id', $id)->delete();

            $item->delete();

            DB::commit();

            // Инвалидируем кэш проектов
            $this->invalidateProjectsCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // Получение истории баланса проекта с кэшированием
    public function getBalanceHistory($projectId)
    {
        $cacheKey = "project_balance_history_{$projectId}";

        return CacheService::remember($cacheKey, function () use ($projectId) {
            // Получаем информацию о проекте для конвертации валют
            $project = \App\Models\Project::find($projectId);
            $projectCurrencyId = $project ? $project->currency_id : null;
            $projectExchangeRate = $project ? $project->exchange_rate : 1;

            // Получаем курсы валют заранее
            $currencyRates = [];
            $currencyHistories = DB::table('currency_histories')
                ->where('start_date', '<=', now()->toDateString())
                ->where(function($query) {
                    $query->whereNull('end_date')
                          ->orWhere('end_date', '>=', now()->toDateString());
                })
                ->orderBy('currency_id')
                ->orderBy('start_date', 'desc')
                ->get()
                ->groupBy('currency_id');

            foreach ($currencyHistories as $currencyId => $histories) {
                $currencyRates[$currencyId] = $histories->first()->exchange_rate ?? 1;
            }

            // Новая архитектура: все операции записываются в таблицу transactions с morphable связями
            $transactions = DB::table('transactions')
                ->leftJoin('cash_registers', 'transactions.cash_id', '=', 'cash_registers.id')
                ->leftJoin('currencies as cash_currencies', 'cash_registers.currency_id', '=', 'cash_currencies.id')
                ->leftJoin('currencies as transaction_currencies', 'transactions.currency_id', '=', 'transaction_currencies.id')
                ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
                ->where('transactions.project_id', $projectId)
                ->select(
                    'transactions.id',
                    'transactions.created_at',
                    'transactions.currency_id',
                    'transactions.orig_amount',
                    'transactions.type',
                    'transactions.source_type',
                    'transactions.source_id',
                    'transactions.is_debt',
                    'transactions.note',
                    'transactions.user_id',
                    'users.name as user_name',
                    DB::raw("CASE
                        WHEN transactions.source_type = 'App\\\\Models\\\\Sale' THEN 'sale'
                        WHEN transactions.source_type = 'App\\\\Models\\\\Order' THEN 'order'
                        WHEN transactions.source_type = 'App\\\\Models\\\\WhReceipt' THEN 'receipt'
                        ELSE 'transaction'
                    END as source"),
                    'transaction_currencies.symbol as cash_currency_symbol'
                );

            // Получаем все транзакции проекта
            $transactionsResult = $transactions
                ->get()
                ->map(function ($item) use ($projectCurrencyId, $projectExchangeRate, $currencyRates) {
                    // Вычисляем сумму в валюте проекта
                    $amount = $item->orig_amount;

                    // Если валюта транзакции != валюта проекта, конвертируем
                    if ($item->currency_id != $projectCurrencyId) {
                        $transactionRate = $currencyRates[$item->currency_id] ?? 1;
                        $amount = ($item->orig_amount * $transactionRate) * $projectExchangeRate;
                    }

                    // Корректируем сумму в зависимости от типа и источника
                    if ($item->source === 'receipt') {
                        $amount = -$amount; // Оприходование - отрицательная (расход)
                    } elseif ($item->source === 'transaction') {
                        $amount = $item->type == 1 ? +$amount : -$amount; // Приход/расход
                    } elseif ($item->source === 'sale') {
                        $amount = +$amount; // Продажа - положительная (приход)
                    } elseif ($item->source === 'order') {
                        $amount = -$amount; // Заказ - отрицательная (расход, тратим ресурсы проекта)
                    }

                    return [
                        'source' => $item->source,
                        'source_id' => $item->id,
                        'source_type' => $item->source_type,
                        'source_source_id' => $item->source_id,
                        'date' => $item->created_at,
                        'amount' => $amount,
                        'orig_amount' => $item->orig_amount,
                        'is_debt' => $item->is_debt,
                        'note' => $item->note,
                        'user_id' => $item->user_id,
                        'user_name' => $item->user_name,
                        'cash_currency_symbol' => $item->cash_currency_symbol,
                        // Отладочная информация
                        'debug_transaction_currency' => $item->currency_id,
                        'debug_transaction_rate' => $currencyRates[$item->currency_id] ?? 1,
                        'debug_project_currency' => $projectCurrencyId,
                        'debug_project_rate' => $projectExchangeRate
                    ];
                });


            // Сортируем транзакции по дате
            $result = $transactionsResult
                ->sortBy('date')
                ->values()
                ->all();

            return $result;
        }, 900); // 15 минут
    }

    // Получение общего баланса проекта (включая долги)
    public function getTotalBalance($projectId)
    {
        $cacheKey = "project_total_balance_{$projectId}";

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $history = $this->getBalanceHistory($projectId);
            return collect($history)->sum('amount');
        }, 900); // 15 минут
    }

    // Получение реального баланса проекта (теперь включает транзакции + заказы напрямую)
    public function getRealBalance($projectId)
    {
        // Реальный баланс = общий баланс (используем единую логику из getBalanceHistory)
        return $this->getTotalBalance($projectId);
    }


    // Получение детального баланса проекта (без разделения на долговой)
    public function getDetailedBalance($projectId)
    {
        $cacheKey = "project_detailed_balance_{$projectId}";

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $balance = $this->getTotalBalance($projectId);
            return [
                'total_balance' => $balance,
                'real_balance' => $balance // Теперь реальный баланс = общему балансу
            ];
        }, 900); // 15 минут
    }


    private function invalidateProjectsCache()
    {
        // Делегируем централизованной службе кэша
        CacheService::invalidateProjectsCache();
    }

    /**
     * Инвалидация кэша конкретного проекта
     */
    public function invalidateProjectCache($projectId)
    {
        \Illuminate\Support\Facades\Cache::forget("project_item_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_balance_history_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_balance_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_total_balance_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_real_balance_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_detailed_balance_{$projectId}");

        // Очищаем кэш с отношениями для всех пользователей
        \Illuminate\Support\Facades\Cache::forget("project_item_relations_{$projectId}_null");
        // Можно добавить очистку для конкретных пользователей, если нужно
    }

    /**
     * Обновление статуса для нескольких проектов
     */
    public function updateStatusByIds(array $ids, int $statusId, string $userId): int
    {
        $targetStatus = \App\Models\ProjectStatus::findOrFail($statusId);
        $updatedCount = 0;

        foreach ($ids as $id) {
            $project = Project::find($id);

            if (!$project) {
                throw new \Exception("Проект ID {$id} не найден");
            }

            // Проверяем доступ пользователя к проекту
            if (!$project->hasUser($userId)) {
                throw new \Exception("Нет доступа к проекту ID {$id}");
            }

            // Обновляем статус только если он изменился
            if ($project->status_id != $statusId) {
                $project->status_id = $statusId;
                $project->save();
                $updatedCount++;
            }
        }

        // Инвалидируем кэш проектов если были изменения
        if ($updatedCount > 0) {
            $this->invalidateProjectsCache();
        }

        return $updatedCount;
    }
}

