<?php

namespace App\Repositories;

use App\Models\Chat;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\ProjectStatus;
use App\Models\ProjectUser;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use App\Models\User;
use App\Services\CacheService;
use App\Services\Chat\ChatService;
use App\Services\ProjectBudgetService;
use App\Support\ProjectBalanceCalculator;
use App\Services\Timeline\TimelineCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Репозиторий для работы с проектами
 */
class ProjectsRepository extends BaseRepository
{
    public function __construct(
        private ?ChatService $chatService = null,
    ) {
    }

    /**
     * Получить базовые связи для проектов
     */
    private function getBaseRelations(): array
    {
        return [
            'client:id,first_name,last_name,client_type',
            'client.phones:id,client_id,phone',
            'client.emails:id,client_id,email',
            'currency:id,name,code',
            'status:id,name,color,is_visible',
            'creator:id,name,surname,photo',
            'users:id,name',
        ];
    }

    /**
     * Синхронизировать пользователей проекта
     *
     * @param  int  $projectId  ID проекта
     * @param  array  $userIds  Массив ID пользователей
     */
    private function syncProjectUsers(int $projectId, array $userIds, ?int $creatorId = null): void
    {
        if ($creatorId !== null) {
            $userIds[] = (int) $creatorId;
        }

        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $this->syncManyToManyUsers(
            ProjectUser::class,
            'project_id',
            $projectId,
            $userIds,
            ['user_column' => 'user_id']
        );
    }

    private function resolveChatService(): ChatService
    {
        return $this->chatService ??= app(ChatService::class);
    }

    private function syncProjectChatIfExists(Project $project): void
    {
        $companyId = (int) $project->company_id;
        if (! $companyId) {
            return;
        }

        $this->resolveChatService()->syncProjectChatFromProject($companyId, $project);
    }

    /**
     * Получить проекты с пагинацией
     *
     * @param  int  $perPage  Количество записей на страницу
     * @param  int  $page  Номер страницы
     * @param  string|null  $search  Поисковый запрос
     * @param  string  $dateFilter  Фильтр по дате
     * @param  string|null  $startDate  Начальная дата
     * @param  string|null  $endDate  Конечная дата
     * @param  int|null  $statusId  ID статуса
     * @param  int|null  $clientId  ID клиента
     * @return LengthAwarePaginator
     */
    public function getItemsWithPagination($perPage = 20, $page = 1, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusId = null, $clientId = null)
    {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('projects_paginated', [$perPage, $search, $dateFilter, $startDate, $endDate, $statusId, $clientId, $currentUser?->id, $companyId]);

        $ttl = (! $search && $dateFilter === 'all_time' && ! $statusId && ! $clientId) ? 1800 : 600;

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $dateFilter, $startDate, $endDate, $page, $statusId, $clientId, $currentUser) {
            $query = Project::select(['projects.*'])
                ->with(array_merge($this->getBaseRelations(), [
                    'projectUsers:id,project_id,user_id',
                ]));

            $query = $this->addCompanyFilterDirect($query, 'projects');
            $this->applyProjectListFilters($query, $search, $dateFilter, $startDate, $endDate, $clientId, $statusId);
            $this->applyOwnFilter($query, 'projects', 'projects', 'creator_id', $currentUser);

            $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', (int) $page);

            return $paginated;
        }, (int) $page);
    }

    /**
     * Получить количество проектов по статусам с учетом тех же фильтров, что и список.
     *
     * Возвращает массив элементов вида `['id' => int, 'name' => string, 'color' => ?string, 'count' => int]`,
     * упорядоченный по `project_statuses.id`. Фильтр по `statusId` намеренно игнорируется,
     * чтобы суммы оставались стабильными при переключении вкладок статусов.
     *
     * @param  string|null  $search  Поисковый запрос
     * @param  string  $dateFilter  Тип фильтра по дате
     * @param  string|null  $startDate  Начальная дата (для кастомного фильтра)
     * @param  string|null  $endDate  Конечная дата (для кастомного фильтра)
     * @param  int|null  $clientId  Фильтр по клиенту
     * @return array<int, array{id:int,name:string,color:?string,count:int}>
     */
    public function getStatusCountsForFilters(
        ?string $search = null,
        string $dateFilter = 'all_time',
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $clientId = null,
    ): array {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();

        $query = Project::query()
            ->leftJoin('project_statuses', 'projects.status_id', '=', 'project_statuses.id');

        $query = $this->addCompanyFilterDirect($query, 'projects');
        $this->applyProjectListFilters($query, $search, $dateFilter, $startDate, $endDate, $clientId);
        $this->applyOwnFilter($query, 'projects', 'projects', 'creator_id', $currentUser);

        return $query
            ->select([
                'project_statuses.id as status_id',
                'project_statuses.name as status_name',
                'project_statuses.color as status_color',
                DB::raw('COUNT(projects.id) as status_count'),
            ])
            ->whereNotNull('projects.status_id')
            ->groupBy('project_statuses.id', 'project_statuses.name', 'project_statuses.color')
            ->orderBy('project_statuses.id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->status_id,
                'name' => (string) $row->status_name,
                'color' => $row->status_color,
                'count' => (int) $row->status_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Получить все проекты
     *
     * @param  bool  $activeOnly  Только активные проекты
     * @return Collection
     */
    public function getAllItems($activeOnly = false)
    {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('projects_all', [$activeOnly, $currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function () use ($activeOnly, $currentUser) {
            $query = Project::select(['projects.*'])
                ->with($this->getBaseRelations());

            $query = $this->addCompanyFilterDirect($query, 'projects');

            if ($activeOnly) {
                $query->join('project_statuses', 'projects.status_id', '=', 'project_statuses.id')
                    ->where('project_statuses.is_visible', true);
            }

            $this->applyOwnFilter($query, 'projects', 'projects', 'creator_id', $currentUser);

            $items = $query->orderBy('created_at', 'desc')->get();

            return $items;
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Создать проект
     *
     * @param  array  $data  Данные проекта
     *
     * @throws \Exception
     */
    public function createItem(array $data): bool
    {
        return DB::transaction(function () use ($data) {
            $companyId = $this->getCurrentCompanyId();

            $item = new Project;
            $item->name = $data['name'];
            $item->budget = 0;
            $item->currency_id = $data['currency_id'] ?? null;
            $item->date = $data['date'];
            $item->creator_id = $data['creator_id'];
            $item->client_id = $data['client_id'];
            $item->company_id = $companyId;
            $item->description = $data['description'] ?? null;
            $item->status_id = $data['status_id'] ?? 1;
            $item->save();

            if (isset($data['users']) && is_array($data['users'])) {
                $this->syncProjectUsers($item->id, $data['users'], (int) $item->creator_id);
            }

            $this->syncProjectChatIfExists($item->fresh(['users:id']));

            CacheService::invalidateProjectsCache();
            TimelineCache::forget('project', (int) $item->id);

            return true;
        });
    }

    /**
     * Обновить проект
     *
     * @param  int  $id  ID проекта
     * @param  array  $data  Данные для обновления
     *
     * @throws \Exception
     */
    public function updateItem(int $id, array $data): bool
    {
        return DB::transaction(function () use ($id, $data) {
            $item = Project::findOrFail($id);
            $previousCurrencyId = $item->currency_id;

            $item->name = $data['name'];
            if (array_key_exists('currency_id', $data)) {
                $rawCurrencyId = $data['currency_id'];
                $newCurrencyId = $rawCurrencyId !== null && $rawCurrencyId !== ''
                    ? (int) $rawCurrencyId
                    : null;
                if (! $item->canChangeCurrencyTo($newCurrencyId)) {
                    throw new \DomainException(__('Нельзя изменить валюту проекта: у проекта есть контракты.'));
                }
                $item->currency_id = $newCurrencyId;
            }
            if (array_key_exists('date', $data)) {
                $item->date = $data['date'];
            }
            $item->client_id = $data['client_id'];
            $item->description = $data['description'] ?? null;

            $item->save();

            if (isset($data['users']) && is_array($data['users'])) {
                $this->syncProjectUsers($id, $data['users'], (int) $item->creator_id);
            }

            $this->syncProjectChatIfExists($item->fresh(['users:id']));

            $previousCurrencyNormalized = $previousCurrencyId !== null ? (int) $previousCurrencyId : null;
            $currentCurrencyNormalized = $item->currency_id !== null ? (int) $item->currency_id : null;
            if (array_key_exists('currency_id', $data) && $previousCurrencyNormalized !== $currentCurrencyNormalized) {
                app(ProjectBudgetService::class)->syncForProject($id);
            }

            CacheService::invalidateProjectsCache();
            TimelineCache::forget('project', $id);

            return true;
        });
    }

    /**
     * Найти проект с отношениями
     *
     * @param  int  $id  ID проекта
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
                'projects.date',
                'projects.creator_id',
                'projects.client_id',
                'projects.status_id',
                'projects.created_at',
                'projects.updated_at',
            ])
                ->with([
                    'client:id,first_name,last_name,client_type',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'creator:id,name,surname,photo',
                    'currency:id,name,code',
                    'status:id,name,color,is_visible',
                    'users:id,name',
                    'projectUsers:id,project_id,user_id',
                ])
                ->where('id', $id);

            return $query->first();
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Удалить проект
     *
     * @param  int  $id  ID проекта
     * @return bool
     *
     * @throws \Exception
     */
    public function deleteItem($id)
    {
        return DB::transaction(function () use ($id) {
            $item = Project::findOrFail($id);

            $transactionsCount = Transaction::where('project_id', $id)
                ->notDeleted()
                ->count();
            if ($transactionsCount > 0) {
                throw new \Exception(__('api.projects.delete_has_transactions_prefix').$transactionsCount);
            }

            if (Chat::query()->where('project_id', $id)->exists()) {
                throw new \Exception(__('api.projects.delete_has_chat'));
            }

            ProjectUser::where('project_id', $id)->delete();

            $item->delete();

            CacheService::invalidateProjectsCache();
            TimelineCache::forget('project', (int) $id);

            return true;
        });
    }

    /**
     * Получить историю баланса проекта
     *
     * @param  int  $projectId  ID проекта
     * @param  int|null  $page  Номер страницы (null — вернуть все, для getTotalBalance)
     * @param  int  $perPage  Записей на странице
     * @param  array{search?: string|null, date_from?: string|null, date_to?: string|null, source?: string|null, transaction_type?: string|null, exclude_debt?: bool|null, is_debt?: bool|null, cash_register_id?: int|null}  $filters
     * @return array|array{history: array, current_page: int, last_page: int, total: int, per_page: int}
     */
    public function getBalanceHistory($projectId, $page = null, $perPage = 20, array $filters = [])
    {
        $cacheKey = $this->generateCacheKey('project_balance_history', [$projectId, $page, $perPage, $filters]);

        return CacheService::remember($cacheKey, function () use ($projectId, $page, $perPage, $filters) {
            $project = Project::find($projectId);
            $currencyContext = app(ProjectBalanceCalculator::class)->resolveCurrencyContext($project);
            $isProjectReportCurrency = $currencyContext['is_report_currency'];
            $isProjectDefaultCurrency = $currencyContext['is_default_currency'];

            $query = $this->projectBalanceTransactionsQuery($projectId);
            $this->applyProjectBalanceHistoryFilters($query, $filters);
            $query->orderBy('created_at', 'desc')
                ->with([
                    'cashRegister.currency:id,code',
                    'currency:id,code,name,is_default',
                    'creator:id,name,surname,photo',
                    'category:id,name',
                ])
                ->select(
                    'id',
                    'created_at',
                    'currency_id',
                    'orig_amount',
                    'rep_amount',
                    'def_amount',
                    'type',
                    'source_type',
                    'source_id',
                    'is_debt',
                    'note',
                    'creator_id',
                    'cash_id',
                    'category_id'
                );

            if ($page !== null && $page >= 1) {
                $total = $query->count();
                $transactions = $query->skip(($page - 1) * $perPage)->take($perPage)->get();
            } else {
                $transactions = $query->get();
            }

            $balanceCalculator = app(ProjectBalanceCalculator::class);
            $transactionsResult = $transactions->map(function ($item) use ($isProjectReportCurrency, $isProjectDefaultCurrency, $balanceCalculator) {
                $balanceAmount = $balanceCalculator->computeSignedAmount(
                    $item,
                    $isProjectReportCurrency,
                    $isProjectDefaultCurrency
                );
                $source = $balanceAmount['source'];
                $amount = $balanceAmount['signed_amount'];

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
                    'creator_id' => $item->creator_id,
                    'creator' => $item->creator ? [
                        'id' => $item->creator->id,
                        'name' => $item->creator->name,
                    ] : null,
                    'cash_currency_symbol' => $item->cashRegister->currency->code ?? $item->currency->code,
                    'category_name' => $item->category?->name ?? null,
                ];
            })->values()->all();

            if ($page !== null && $page >= 1) {
                return [
                    'history' => $transactionsResult,
                    'current_page' => $page,
                    'last_page' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                    'total' => $total,
                    'per_page' => $perPage,
                ];
            }

            return $transactionsResult;
        }, 900);
    }

    /**
     * Транзакции, участвующие в балансе проекта (без долговых начислений по контрактам).
     *
     * @param  int  $projectId
     * @return Builder
     */
    private function projectBalanceTransactionsQuery(int $projectId): Builder
    {
        return Transaction::query()
            ->where('project_id', $projectId)
            ->notDeleted()
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('source_type', '!=', ProjectContract::class)
                        ->orWhereNull('source_type');
                })->orWhere('is_debt', false);
            });
    }

    /**
     * @param  Builder  $query
     * @param  array{search?: string|null, date_from?: string|null, date_to?: string|null, source?: string|null, transaction_type?: string|null, exclude_debt?: bool|null, is_debt?: bool|null, cash_register_id?: int|null}  $filters
     */
    private function applyProjectBalanceHistoryFilters(Builder $query, array $filters): void
    {
        if (($filters['exclude_debt'] ?? null) === true) {
            $query->where('is_debt', false);
        }
        if (($filters['is_debt'] ?? null) === true) {
            $query->where('is_debt', true);
        }

        $cashRegisterId = isset($filters['cash_register_id']) ? (int) $filters['cash_register_id'] : null;
        if ($cashRegisterId > 0) {
            $query->where('cash_id', $cashRegisterId);
        }

        $dateFrom = $filters['date_from'] ?? null;
        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        $dateTo = $filters['date_to'] ?? null;
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $source = $filters['source'] ?? null;
        if ($source === 'sale') {
            $query->where('source_type', 'App\\Models\\Sale');
        } elseif ($source === 'order') {
            $query->where('source_type', 'App\\Models\\Order');
        } elseif ($source === 'receipt') {
            $query->where('source_type', 'App\\Models\\WhReceipt');
        } elseif ($source === 'transaction') {
            $query->where(function ($q) {
                $q->whereNull('source_type')
                    ->orWhereNotIn('source_type', [
                        'App\\Models\\Sale',
                        'App\\Models\\Order',
                        'App\\Models\\WhReceipt',
                    ]);
            });
        }

        $transactionType = $filters['transaction_type'] ?? null;
        if ($transactionType === 'income') {
            $query->where('type', 1);
        } elseif ($transactionType === 'outcome') {
            $query->where('type', 0);
        }

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            $query->where(function ($q) use ($search, $searchLower) {
                $q->where('transactions.id', 'like', "%{$search}%")
                    ->orWhereRaw('LOWER(transactions.note) LIKE ?', ["%{$searchLower}%"])
                    ->orWhereHas('category', function ($categoryQuery) use ($searchLower) {
                        $categoryQuery->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"]);
                    })
                    ->orWhereHas('creator', function ($creatorQuery) use ($searchLower) {
                        $creatorQuery->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"]);
                    });
            });
        }
    }

    /**
     * Получить общий баланс проекта
     *
     * @param  int  $projectId  ID проекта
     * @return float
     */
    public function getTotalBalance($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_total_balance', [$projectId]);

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $history = $this->getBalanceHistory($projectId);
            $stats = $this->calculateBalanceStats($history);

            return $stats['balance'];
        }, $this->getCacheTTL('reference', true));
    }

    /**
     * Получить детальный баланс проекта
     *
     * @param  int  $projectId  ID проекта
     * @return array
     */
    public function getDetailedBalance($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_detailed_balance', [$projectId]);

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $history = $this->getBalanceHistory($projectId);
            $stats = $this->calculateBalanceStats($history);

            return [
                'total_balance' => $stats['balance'],
                'total_income' => $stats['income'],
                'total_expense' => $stats['expense'],
            ];
        }, $this->getCacheTTL('reference', true));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string|null  $search
     * @param  string  $dateFilter
     * @param  string|null  $startDate
     * @param  string|null  $endDate
     * @param  int|null  $clientId
     * @param  int|null  $statusId
     */
    private function applyProjectListFilters(
        $query,
        $search,
        string $dateFilter = 'all_time',
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $clientId = null,
        ?int $statusId = null,
    ): void {
        if ($search) {
            $searchTrimmed = trim((string) $search);
            $searchLower = mb_strtolower($searchTrimmed);
            $query->where(function ($q) use ($searchTrimmed, $searchLower) {
                $q->where('projects.id', 'like', "%{$searchTrimmed}%")
                    ->orWhereRaw('LOWER(projects.name) LIKE ?', ["%{$searchLower}%"]);

                $q->orWhereHas('client', function ($clientQuery) use ($searchTrimmed) {
                    $this->applyClientSearchConditions($clientQuery, $searchTrimmed);
                })
                    ->orWhereHas('client.phones', function ($phoneQuery) use ($searchLower) {
                        $phoneQuery->whereRaw('LOWER(phone) LIKE ?', ["%{$searchLower}%"]);
                    })
                    ->orWhereHas('client.emails', function ($emailQuery) use ($searchLower) {
                        $emailQuery->whereRaw('LOWER(email) LIKE ?', ["%{$searchLower}%"]);
                    });
            });
        }

        if ($dateFilter && $dateFilter !== 'all_time') {
            $this->applyDateFilter($query, $dateFilter, $startDate, $endDate, 'projects.date');
        }

        if ($clientId) {
            $query->where('projects.client_id', $clientId);
        }

        if ($statusId) {
            $query->where('projects.status_id', $statusId);
        }
    }

    /**
     * Рассчитать агрегированные показатели баланса проекта
     *
     * @param  array  $history  История баланса проекта
     * @return array{balance: float, income: float, expense: float}
     */
    private function calculateBalanceStats(array $history): array
    {
        $balance = 0.0;
        $income = 0.0;
        $expense = 0.0;

        foreach ($history as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            $balance += $amount;

            if ($amount > 0) {
                $income += $amount;
            } elseif ($amount < 0) {
                $expense += abs($amount);
            }
        }

        return [
            'balance' => $balance,
            'income' => $income,
            'expense' => $expense,
        ];
    }

    /**
     * Инвалидировать кэш конкретного проекта
     *
     * @param  int  $projectId  ID проекта
     */
    public function invalidateProjectCache($projectId): void
    {
        CacheService::invalidateProjectCache((int) $projectId);
    }

    /**
     * Массовое обновление статуса проектов
     *
     * @param  array  $ids  Массив ID проектов
     * @param  int  $statusId  ID нового статуса
     * @return int Количество обновленных проектов
     *
     * @throws \Exception
     */
    public function updateStatusByIds(array $ids, int $statusId): int
    {
        $targetStatus = ProjectStatus::findOrFail($statusId);

        $projects = Project::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            if (! $projects->has($id)) {
                throw new \Exception("Проект ID {$id} не найден");
            }
        }

        $idsToUpdate = [];
        foreach ($ids as $id) {
            /** @var Project $project */
            $project = $projects->get($id);
            if ((int) $project->status_id !== (int) $statusId) {
                $idsToUpdate[] = (int) $id;
            }
        }

        $updatedCount = 0;
        if ($idsToUpdate !== []) {
            Project::query()->whereIn('id', $idsToUpdate)->update(['status_id' => $statusId]);
            $updatedCount = count($idsToUpdate);
            CacheService::invalidateProjectsCache();
            foreach ($idsToUpdate as $projectId) {
                TimelineCache::forget('project', $projectId);
            }
        }

        return $updatedCount;
    }
}
