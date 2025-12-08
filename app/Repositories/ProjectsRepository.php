<?php

namespace App\Repositories;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Services\CacheService;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Репозиторий для работы с проектами
 */
class ProjectsRepository extends BaseRepository
{
    /**
     * Получить базовые связи для проектов
     *
     * @return array
     */
    private function getBaseRelations(): array
    {
        return [
            'client:id,first_name,last_name,contact_person,balance',
            'client.phones:id,client_id,phone',
            'client.emails:id,client_id,email',
            'currency:id,name,code,symbol',
            'status:id,name,color',
            'creator:id,name,photo',
            'users:id,name',
        ];
    }

    /**
     * Синхронизировать пользователей проекта
     *
     * @param int $projectId ID проекта
     * @param array $userIds Массив ID пользователей
     * @return void
     */
    private function syncProjectUsers(int $projectId, array $userIds): void
    {
        $this->syncManyToManyUsers(
            ProjectUser::class,
            'project_id',
            $projectId,
            $userIds
        );
    }

    /**
     * Получить проекты с пагинацией
     *
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @param string|null $search Поисковый запрос
     * @param string $dateFilter Фильтр по дате
     * @param string|null $startDate Начальная дата
     * @param string|null $endDate Конечная дата
     * @param int|null $statusId ID статуса
     * @param int|null $clientId ID клиента
     * @param string|null $contractType Тип контракта
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($perPage = 20, $page = 1, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusId = null, $clientId = null, $contractType = null)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('projects_paginated', [$perPage, $search, $dateFilter, $startDate, $endDate, $statusId, $clientId, $contractType, $currentUser?->id, $companyId]);

        $ttl = (!$search && $dateFilter === 'all_time' && !$statusId && !$clientId && $contractType === null) ? 1800 : 600;

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $dateFilter, $startDate, $endDate, $page, $statusId, $clientId, $contractType, $currentUser) {
            $query = Project::select(['projects.*'])
                ->with(array_merge($this->getBaseRelations(), [
                    'projectUsers:id,project_id,user_id'
                ]));

            $query = $this->addCompanyFilterDirect($query, 'projects');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('projects.id', 'like', "%{$search}%")
                        ->orWhere('projects.name', 'like', "%{$search}%");
                    $this->applyClientSearchFilterThroughRelation($q, 'client', $search);
                });
            }

            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter, $startDate, $endDate, 'projects.date');
            }

            if ($statusId) {
                $query->where('projects.status_id', $statusId);
            }

            if ($clientId) {
                $query->where('projects.client_id', $clientId);
            }

            $this->applyOwnFilter($query, 'projects', 'projects', 'user_id', $currentUser);

            return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }



    /**
     * Получить все проекты
     *
     * @param bool $activeOnly Только активные проекты
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($activeOnly = false)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('projects_all', [$activeOnly, $currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function () use ($activeOnly, $currentUser) {
            $query = Project::select(['projects.*'])
                ->with($this->getBaseRelations());

            $query = $this->addCompanyFilterDirect($query, 'projects');

            if ($activeOnly) {
                $query->whereNotIn('projects.status_id', [3, 4]);
            }

            $this->applyOwnFilter($query, 'projects', 'projects', 'user_id', $currentUser);

            return $query->orderBy('created_at', 'desc')->get();
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Создать проект
     *
     * @param array $data Данные проекта
     * @return bool
     * @throws \Exception
     */
    public function createItem(array $data): bool
    {
        return DB::transaction(function () use ($data) {
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
            $item->status_id = $data['status_id'] ?? 1;
            $item->save();

            if (isset($data['users']) && is_array($data['users'])) {
                $this->syncProjectUsers($item->id, $data['users']);
            }

            CacheService::invalidateProjectsCache();

            return true;
        });
    }

    /**
     * Обновить проект
     *
     * @param int $id ID проекта
     * @param array $data Данные для обновления
     * @return bool
     * @throws \Exception
     */
    public function updateItem(int $id, array $data): bool
    {
        return DB::transaction(function () use ($id, $data) {
            $item = Project::findOrFail($id);

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

            if (isset($data['users']) && is_array($data['users'])) {
                $this->syncProjectUsers($id, $data['users']);
            }

            CacheService::invalidateProjectsCache();

            return true;
        });
    }

    /**
     * Найти проект с отношениями
     *
     * @param int $id ID проекта
     * @return Project|null
     */
    public function findItemWithRelations($id)
    {
        $cacheKey = $this->generateCacheKey('project_item_relations', [$id]);

        return CacheService::remember($cacheKey, function () use ($id) {
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

            $result = $query->first();


            return $result;
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Удалить проект
     *
     * @param int $id ID проекта
     * @return bool
     * @throws \Exception
     */
    public function deleteItem($id)
    {
        return DB::transaction(function () use ($id) {
            $item = Project::findOrFail($id);

            $transactionsCount = \App\Models\Transaction::where('project_id', $id)
                ->where('is_deleted', false)
                ->count();
            if ($transactionsCount > 0) {
                throw new \Exception('Невозможно удалить проект, к нему привязано транзакций: ' . $transactionsCount);
            }

            ProjectUser::where('project_id', $id)->delete();

            $item->delete();

            CacheService::invalidateProjectsCache();

            return true;
        });
    }

    /**
     * Получить историю баланса проекта
     *
     * @param int $projectId ID проекта
     * @return array
     */
    public function getBalanceHistory($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_balance_history', [$projectId]);

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $project = \App\Models\Project::find($projectId);
            $projectCurrencyId = $project ? $project->currency_id : null;
            $projectExchangeRate = $project ? $project->exchange_rate : 1;

            $companyId = $project ? $project->company_id : null;
            $currencyRates = [];

            $currencyHistoriesQuery = \App\Models\CurrencyHistory::where('start_date', '<=', now()->toDateString())
                ->where(function ($query) {
                    $query->whereNull('end_date')
                        ->orWhere('end_date', '>=', now()->toDateString());
                });

            if ($companyId) {
                $currencyHistoriesQuery->where('company_id', $companyId);
            } else {
                $currencyHistoriesQuery->whereNull('company_id');
            }

            $currencyHistories = $currencyHistoriesQuery
                ->orderBy('currency_id')
                ->orderBy('start_date', 'desc')
                ->get()
                ->groupBy('currency_id');

            $currencyRates = $currencyHistories->map(fn($histories) => $histories->first()?->exchange_rate)->toArray();

            $transactions = Transaction::where('project_id', $projectId)
                ->where('is_deleted', false)
                ->with([
                    'cashRegister.currency:id,symbol',
                    'currency:id,symbol',
                    'user:id,name'
                ])
                ->select(
                    'id',
                    'created_at',
                    'currency_id',
                    'orig_amount',
                    'type',
                    'source_type',
                    'source_id',
                    'is_debt',
                    'note',
                    'user_id',
                    'cash_id'
                );

            $sourceMap = [
                'App\\Models\\Sale' => 'sale',
                'App\\Models\\Order' => 'order',
                'App\\Models\\WhReceipt' => 'receipt',
            ];

            $transactionsResult = $transactions->get()->map(function ($item) use ($projectCurrencyId, $projectExchangeRate, $currencyRates, $sourceMap) {
                $source = $sourceMap[$item->source_type] ?? 'transaction';
                $amount = $item->orig_amount;

                if ($item->currency_id != $projectCurrencyId) {
                    $transactionRate = $currencyRates[$item->currency_id] ?? 1;
                    $amount = ($item->orig_amount * $transactionRate) * $projectExchangeRate;
                }

                $amount = match($source) {
                    'receipt' => -$amount,
                    'transaction' => $item->type == 1 ? +$amount : -$amount,
                    'sale' => +$amount,
                    'order' => -$amount,
                    default => $amount
                };

                return [
                    'source' => $source,
                    'source_id' => $item->id,
                    'source_type' => $item->source_type,
                    'source_source_id' => $item->source_id,
                    'date' => $item->created_at,
                    'amount' => $amount,
                    'orig_amount' => $item->orig_amount,
                    'is_debt' => $item->is_debt,
                    'note' => $item->note,
                    'user_id' => $item->user_id,
                    'user_name' => $item->user->name,
                    'cash_currency_symbol' => $item->cashRegister?->currency->symbol ?? $item->currency->symbol,
                    'debug_transaction_currency' => $item->currency_id,
                    'debug_transaction_rate' => $currencyRates[$item->currency_id] ?? 1,
                    'debug_project_currency' => $projectCurrencyId,
                    'debug_project_rate' => $projectExchangeRate
                ];
            });

            $result = $transactionsResult
                ->sortBy('date')
                ->values()
                ->all();

            return $result;
        }, 900);
    }

    /**
     * Получить общий баланс проекта
     *
     * @param int $projectId ID проекта
     * @return float
     */
    public function getTotalBalance($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_total_balance', [$projectId]);

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $history = $this->getBalanceHistory($projectId);
            return collect($history)->sum('amount');
        }, $this->getCacheTTL('reference', true));
    }

    /**
     * Получить детальный баланс проекта
     *
     * @param int $projectId ID проекта
     * @return array
     */
    public function getDetailedBalance($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_detailed_balance', [$projectId]);

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $balance = $this->getTotalBalance($projectId);
            return [
                'total_balance' => $balance,
                'real_balance' => $balance
            ];
        }, $this->getCacheTTL('reference', true));
    }

    /**
     * Инвалидировать кэш конкретного проекта
     *
     * @param int $projectId ID проекта
     * @return void
     */
    public function invalidateProjectCache($projectId): void
    {
        CacheService::forget("project_item_{$projectId}");
        CacheService::forget("project_balance_history_{$projectId}");
        CacheService::forget("project_balance_{$projectId}");
        CacheService::forget("project_total_balance_{$projectId}");
        CacheService::forget("project_real_balance_{$projectId}");
        CacheService::forget("project_detailed_balance_{$projectId}");
        CacheService::forget("project_item_relations_{$projectId}_null");
    }

    /**
     * Массовое обновление статуса проектов
     *
     * @param array $ids Массив ID проектов
     * @param int $statusId ID нового статуса
     * @param string $userId ID пользователя
     * @return int Количество обновленных проектов
     * @throws \Exception
     */
    public function updateStatusByIds(array $ids, int $statusId, string $userId): int
    {
        $targetStatus = \App\Models\ProjectStatus::findOrFail($statusId);

        $projects = Project::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            if (!$projects->has($id)) {
                throw new \Exception("Проект ID {$id} не найден");
            }
        }

        $updatedCount = 0;

        foreach ($ids as $id) {
            /** @var \App\Models\Project $project */
            $project = $projects->get($id);

            if ($project->status_id != $statusId) {
                $project->status_id = $statusId;
                $project->save();
                $updatedCount++;
            }
        }

        if ($updatedCount > 0) {
            CacheService::invalidateProjectsCache();
        }

        return $updatedCount;
    }
}
