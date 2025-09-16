<?php

namespace App\Repositories;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Repositories\ClientsRepository;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class ProjectsRepository
{
    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusId = null, $clientId = null, $contractType = null)
    {
        // Создаем уникальный ключ кэша
        $cacheKey = "projects_paginated_{$userUuid}_{$perPage}_{$search}_{$dateFilter}_{$startDate}_{$endDate}_{$statusId}_{$clientId}_{$contractType}";

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
                'users.name as user_name'
            ])
                ->leftJoin('clients', 'projects.client_id', '=', 'clients.id')
                ->leftJoin('users', 'projects.user_id', '=', 'users.id')
                ->with([
                    'client:id,first_name,last_name,contact_person',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'client.balance:id,client_id,balance',
                    'currency:id,name,code,symbol',
                    'status:id,name,color',
                    'creator:id,name',
                    'users:id,name',
                    'projectUsers:id,project_id,user_id'
                ]);

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
            return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
        }, $page);
    }

    /**
     * Быстрый поиск проектов с оптимизированным кэшированием
     */
    public function fastSearch($userUuid, $search, $perPage = 20)
    {
        $cacheKey = "projects_fast_search_{$userUuid}_{$search}_{$perPage}";

        return CacheService::rememberSearch($cacheKey, function () use ($userUuid, $search, $perPage) {
            return Project::select([
                'projects.id',
                'projects.name',
                'projects.budget',
                'projects.date',
                'projects.created_at',
                'clients.first_name as client_first_name',
                'clients.last_name as client_last_name'
            ])
                ->leftJoin('clients', 'projects.client_id', '=', 'clients.id')
                ->whereHas('projectUsers', function ($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                })
                ->where(function ($q) use ($search) {
                    $q->where('projects.id', 'like', "%{$search}%")
                        ->orWhere('projects.name', 'like', "%{$search}%")
                        ->orWhere('clients.first_name', 'like', "%{$search}%")
                        ->orWhere('clients.last_name', 'like', "%{$search}%");
                })
                ->orderBy('projects.created_at', 'desc')
                ->paginate($perPage);
        });
    }

    private function applyDateFilter($query, $dateFilter, $startDate, $endDate)
    {
        if ($dateFilter === 'today') {
            $query->whereDate('projects.date', now()->toDateString());
        } elseif ($dateFilter === 'yesterday') {
            $query->whereDate('projects.date', now()->subDay()->toDateString());
        } elseif ($dateFilter === 'this_week') {
            $query->whereBetween('projects.date', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($dateFilter === 'this_month') {
            $query->whereBetween('projects.date', [now()->startOfMonth(), now()->endOfMonth()]);
        } elseif ($dateFilter === 'this_year') {
            $query->whereBetween('projects.date', [now()->startOfYear(), now()->endOfYear()]);
        } elseif ($dateFilter === 'last_week') {
            $query->whereBetween('projects.date', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()]);
        } elseif ($dateFilter === 'last_month') {
            $query->whereBetween('projects.date', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()]);
        } elseif ($dateFilter === 'last_year') {
            $query->whereBetween('projects.date', [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()]);
        } elseif ($dateFilter === 'custom') {
            if ($startDate && $endDate) {
                $query->whereBetween('projects.date', [$startDate, $endDate]);
            }
        }
    }

    // Получение всего списка
    public function getAllItems($userUuid)
    {
        $cacheKey = "projects_all_{$userUuid}";

        return CacheService::remember($cacheKey, function () use ($userUuid) {
            return Project::select([
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
                'projects.updated_at',
                'clients.first_name as client_first_name',
                'clients.last_name as client_last_name',
                'users.name as user_name'
            ])
                ->leftJoin('clients', 'projects.client_id', '=', 'clients.id')
                ->leftJoin('users', 'projects.user_id', '=', 'users.id')
                ->with([
                    'client:id,first_name,last_name,contact_person',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'client.balance:id,client_id,balance',
                    'currency:id,name,code,symbol',
                    'creator:id,name',
                    'users:id,name'
                ])
                ->whereHas('projectUsers', function ($query) use ($userUuid) {
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
            $item = new Project();
            $item->name = $data['name'];
            $item->budget = $data['budget'] ?? 0;
            $item->currency_id = $data['currency_id'] ?? null;
            $item->exchange_rate = $data['exchange_rate'] ?? null;
            $item->date = $data['date'];
            $item->user_id = $data['user_id'];
            $item->client_id = $data['client_id'];
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

    public function findItem($id)
    {
        $cacheKey = "project_item_{$id}";

        return CacheService::remember($cacheKey, function () use ($id) {
            return Project::with([
                'client:id,first_name,last_name,contact_person',
                'client.phones:id,client_id,phone',
                'client.emails:id,client_id,email',
                'client.balance:id,client_id,balance',
                'currency:id,name,code,symbol',
                'users:id,name',
                'projectUsers:id,project_id,user_id'
            ])->find($id);
        }, 1800); // 30 минут
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
                    'client:id,first_name,last_name,contact_person',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'client.balance:id,client_id,balance',
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

            return $query->first();
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

            // Оптимизированный запрос с UNION вместо отдельных запросов
            $sales = DB::table('sales')
                ->leftJoin('cash_registers', 'sales.cash_id', '=', 'cash_registers.id')
                ->leftJoin('currencies', 'cash_registers.currency_id', '=', 'currencies.id')
                ->where('sales.project_id', $projectId)
                ->select(
                    'sales.id',
                    'sales.created_at',
                    DB::raw("CASE
                        WHEN sales.cash_id IS NOT NULL AND cash_registers.currency_id != {$projectCurrencyId}
                        THEN sales.price * {$projectExchangeRate}
                        ELSE sales.price
                    END as amount"),
                    DB::raw("CASE WHEN sales.cash_id IS NOT NULL THEN sales.price ELSE NULL END as orig_amount"),
                    'sales.cash_id',
                    DB::raw("NULL as type"),
                    DB::raw("'sale' as source"),
                    DB::raw("CASE WHEN sales.cash_id IS NOT NULL THEN 'Продажа через кассу' ELSE 'Продажа в баланс(долг)' END as description"),
                    'currencies.symbol as cash_currency_symbol',
                    'currencies.code as cash_currency_code'
                );

            $receipts = DB::table('wh_receipts')
                ->leftJoin('cash_registers', 'wh_receipts.cash_id', '=', 'cash_registers.id')
                ->leftJoin('currencies', 'cash_registers.currency_id', '=', 'currencies.id')
                ->where('wh_receipts.project_id', $projectId)
                ->select(
                    'wh_receipts.id',
                    'wh_receipts.created_at',
                    DB::raw("CASE
                        WHEN wh_receipts.cash_id IS NOT NULL AND cash_registers.currency_id != {$projectCurrencyId}
                        THEN wh_receipts.amount * {$projectExchangeRate}
                        ELSE wh_receipts.amount
                    END as amount"),
                    DB::raw("CASE WHEN wh_receipts.cash_id IS NOT NULL THEN wh_receipts.amount ELSE NULL END as orig_amount"),
                    'wh_receipts.cash_id',
                    DB::raw("NULL as type"),
                    DB::raw("'receipt' as source"),
                    DB::raw("CASE WHEN wh_receipts.cash_id IS NOT NULL THEN 'Долг за оприходование(в кассу)' ELSE 'Долг за оприходование(в баланс)' END as description"),
                    'currencies.symbol as cash_currency_symbol',
                    'currencies.code as cash_currency_code'
                );

            $transactions = DB::table('transactions')
                ->leftJoin('cash_registers', 'transactions.cash_id', '=', 'cash_registers.id')
                ->leftJoin('currencies', 'cash_registers.currency_id', '=', 'currencies.id')
                ->where('transactions.project_id', $projectId)
                ->select(
                    'transactions.id',
                    'transactions.created_at',
                    DB::raw("CASE
                        WHEN transactions.cash_id IS NOT NULL AND cash_registers.currency_id != {$projectCurrencyId}
                        THEN transactions.amount * {$projectExchangeRate}
                        ELSE transactions.amount
                    END as amount"),
                    'transactions.orig_amount',
                    'transactions.cash_id',
                    'transactions.type',
                    DB::raw("'transaction' as source"),
                    DB::raw("CASE WHEN transactions.type = 1 THEN 'Приход в проект' ELSE 'Расход из проекта' END as description"),
                    'currencies.symbol as cash_currency_symbol',
                    'currencies.code as cash_currency_code'
                );

            $orders = DB::table('orders')
                ->leftJoin('cash_registers', 'orders.cash_id', '=', 'cash_registers.id')
                ->leftJoin('currencies', 'cash_registers.currency_id', '=', 'currencies.id')
                ->where('orders.project_id', $projectId)
                ->select(
                    'orders.id',
                    'orders.created_at',
                    DB::raw("CASE
                        WHEN orders.cash_id IS NOT NULL AND cash_registers.currency_id != {$projectCurrencyId}
                        THEN orders.total_price * {$projectExchangeRate}
                        ELSE orders.total_price
                    END as amount"),
                    DB::raw("CASE WHEN orders.cash_id IS NOT NULL THEN orders.total_price ELSE NULL END as orig_amount"),
                    'orders.cash_id',
                    DB::raw("NULL as type"),
                    DB::raw("'order' as source"),
                    DB::raw("'Заказ' as description"),
                    'currencies.symbol as cash_currency_symbol',
                    'currencies.code as cash_currency_code'
                );

            // Объединяем все запросы
            $result = $sales->union($receipts)
                ->union($transactions)
                ->union($orders)
                ->orderBy('created_at')
                ->get()
                ->map(function ($item) {
                    $amount = $item->amount;

                    // Корректируем сумму в зависимости от типа
                    if ($item->source === 'receipt' && isset($item->cash_id) && $item->cash_id) {
                        $amount = -$amount; // Долг за оприходование
                    } elseif ($item->source === 'transaction' && isset($item->type)) {
                        $amount = $item->type == 1 ? +$amount : -$amount; // Приход/расход
                    } elseif ($item->source === 'sale' && isset($item->cash_id) && $item->cash_id) {
                        $amount = +$amount; // Продажа через кассу - положительная
                    } elseif ($item->source === 'order' && isset($item->cash_id) && $item->cash_id) {
                        $amount = +$amount; // Заказ через кассу - положительная
                    }

                    return [
                        'source' => $item->source,
                        'source_id' => $item->id,
                        'date' => $item->created_at,
                        'amount' => $amount,
                        'description' => $item->description,
                        'currency_id' => $item->currency_id ?? null
                    ];
                })
                ->values()
                ->all();

            return $result;
        }, 900); // 15 минут
    }

    // Получение текущего баланса проекта с кэшированием
    public function getBalance($projectId)
    {
        $cacheKey = "project_balance_{$projectId}";

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $history = $this->getBalanceHistory($projectId);
            return collect($history)->sum('amount');
        }, 900); // 15 минут
    }


    /**
     * Инвалидация кэша проектов
     */
    private function invalidateProjectsCache()
    {
        // Очищаем кэш, связанный с проектами
        $keys = [
            'projects_paginated_*',
            'projects_all_*',
            'projects_fast_search_*',
            'project_item_*',
            'project_balance_history_*',
            'project_balance_*'
        ];

        foreach ($keys as $key) {
            if (str_contains($key, '*')) {
                // Для паттернов с wildcard очищаем весь кэш
                \Illuminate\Support\Facades\Cache::flush();
                break;
            } else {
                \Illuminate\Support\Facades\Cache::forget($key);
            }
        }
    }

    /**
     * Инвалидация кэша конкретного проекта
     */
    public function invalidateProjectCache($projectId)
    {
        \Illuminate\Support\Facades\Cache::forget("project_item_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_balance_history_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_balance_{$projectId}");

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

