<?php

namespace App\Repositories;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class ProjectsRepository extends BaseRepository
{

    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusId = null, $clientId = null, $contractType = null)
    {
        $cacheKey = $this->generateCacheKey('projects_paginated', [$userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $statusId, $clientId, $contractType]);

        $ttl = (!$search && $dateFilter === 'all_time' && !$statusId && !$clientId && $contractType === null) ? 1800 : 600;

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $page, $statusId, $clientId, $contractType) {
            $query = Project::select([
                'projects.*'
            ])
                ->with([
                    'client:id,first_name,last_name,contact_person,balance',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'currency:id,name,code,symbol',
                    'status:id,name,color',
                    'creator:id,name,photo',
                    'users:id,name',
                    'projectUsers:id,project_id,user_id'
                ]);

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

            $query->whereHas('projectUsers', function ($query) use ($userUuid) {
                $query->where('user_id', $userUuid);
            });

            return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }



    public function getAllItems($userUuid, $activeOnly = false)
    {
        $cacheKey = $this->generateCacheKey('projects_all', [$userUuid, $activeOnly]);

        return CacheService::getReferenceData($cacheKey, function () use ($userUuid, $activeOnly) {
            $query = Project::select([
                'projects.*'
            ])
                ->with([
                    'client:id,first_name,last_name,contact_person,balance',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'currency:id,name,code,symbol',
                    'status:id,name,color',
                    'creator:id,name,photo',
                    'users:id,name'
                ]);

            $query = $this->addCompanyFilterDirect($query, 'projects');

            if ($activeOnly) {
                $query->whereNotIn('projects.status_id', [3, 4]);
            }

            return $query->whereHas('projectUsers', function ($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                })
                ->orderBy('created_at', 'desc')
                ->get();
        }, 1800);
    }

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
            $item->status_id = $data['status_id'] ?? 1;
            $item->save();

            foreach ($data['users'] as $userId) {
                ProjectUser::create([
                    'project_id' => $item->id,
                    'user_id' => $userId
                ]);

            }

            DB::commit();

            $this->invalidateProjectsCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateItem($id, $data)
    {
        DB::beginTransaction();
        try {
            $item = Project::find($id);
            if (!$item) {
                throw new \Exception('Project not found');
            }

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

            ProjectUser::where('project_id', $id)->delete();

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
        $cacheKey = $this->generateCacheKey('project_item_relations', [$id, $userUuid]);

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
        }, 1800);
    }

    public function deleteItem($id)
    {
        DB::beginTransaction();
        try {
            $item = Project::find($id);
            if (!$item) {
                return false;
            }

            $transactionsCount = \App\Models\Transaction::where('project_id', $id)
                ->where('is_deleted', false)
                ->count();
            if ($transactionsCount > 0) {
                throw new \Exception('Невозможно удалить проект, к нему привязано транзакций: ' . $transactionsCount);
            }

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

    public function getBalanceHistory($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_balance_history', [$projectId]);

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $project = \App\Models\Project::find($projectId);
            $projectCurrencyId = $project ? $project->currency_id : null;
            $projectExchangeRate = $project ? $project->exchange_rate : 1;

            $companyId = $project ? $project->company_id : null;
            $currencyRates = [];

            $currencyHistoriesQuery = DB::table('currency_histories')
                ->where('start_date', '<=', now()->toDateString())
                ->where(function($query) {
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

            foreach ($currencyHistories as $currencyId => $histories) {
                $currencyRates[$currencyId] = $histories->first()->exchange_rate ?? 1;
            }

            $transactions = DB::table('transactions')
                ->leftJoin('cash_registers', 'transactions.cash_id', '=', 'cash_registers.id')
                ->leftJoin('currencies as cash_currencies', 'cash_registers.currency_id', '=', 'cash_currencies.id')
                ->leftJoin('currencies as transaction_currencies', 'transactions.currency_id', '=', 'transaction_currencies.id')
                ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
                ->where('transactions.project_id', $projectId)
                ->where('transactions.is_deleted', false)
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

            $transactionsResult = $transactions
                ->get()
                ->map(function ($item) use ($projectCurrencyId, $projectExchangeRate, $currencyRates) {
                    $amount = $item->orig_amount;

                    if ($item->currency_id != $projectCurrencyId) {
                        $transactionRate = $currencyRates[$item->currency_id] ?? 1;
                        $amount = ($item->orig_amount * $transactionRate) * $projectExchangeRate;
                    }

                    if ($item->source === 'receipt') {
                        $amount = -$amount;
                    } elseif ($item->source === 'transaction') {
                        $amount = $item->type == 1 ? +$amount : -$amount;
                    } elseif ($item->source === 'sale') {
                        $amount = +$amount;
                    } elseif ($item->source === 'order') {
                        $amount = -$amount;
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

    public function getTotalBalance($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_total_balance', [$projectId]);

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $history = $this->getBalanceHistory($projectId);
            return collect($history)->sum('amount');
        }, 900);
    }

    public function getRealBalance($projectId)
    {
        return $this->getTotalBalance($projectId);
    }

    public function getDetailedBalance($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_detailed_balance', [$projectId]);

        return CacheService::remember($cacheKey, function () use ($projectId) {
            $balance = $this->getTotalBalance($projectId);
            return [
                'total_balance' => $balance,
                'real_balance' => $balance
            ];
        }, 900);
    }

    private function invalidateProjectsCache()
    {
        CacheService::invalidateProjectsCache();
    }

    public function invalidateProjectCache($projectId)
    {
        \Illuminate\Support\Facades\Cache::forget("project_item_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_balance_history_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_balance_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_total_balance_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_real_balance_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_detailed_balance_{$projectId}");
        \Illuminate\Support\Facades\Cache::forget("project_item_relations_{$projectId}_null");
    }

    public function updateStatusByIds(array $ids, int $statusId, string $userId): int
    {
        $targetStatus = \App\Models\ProjectStatus::findOrFail($statusId);
        $updatedCount = 0;

        foreach ($ids as $id) {
            $project = Project::find($id);

            if (!$project) {
                throw new \Exception("Проект ID {$id} не найден");
            }

            if (!$project->hasUser($userId)) {
                throw new \Exception("Нет доступа к проекту ID {$id}");
            }

            if ($project->status_id != $statusId) {
                $project->status_id = $statusId;
                $project->save();
                $updatedCount++;
            }
        }

        if ($updatedCount > 0) {
            $this->invalidateProjectsCache();
        }

        return $updatedCount;
    }
}

