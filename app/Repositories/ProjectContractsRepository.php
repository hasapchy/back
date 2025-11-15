<?php

namespace App\Repositories;

use App\Models\ProjectContract;
use App\Models\Project;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

/**
 * Репозиторий для работы с контрактами проектов
 */
class ProjectContractsRepository extends BaseRepository
{
    /**
     * Получить базовый запрос контрактов с валютами
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function getBaseQuery()
    {
        return ProjectContract::select([
            'project_contracts.id',
            'project_contracts.project_id',
            'project_contracts.number',
            'project_contracts.amount',
            'project_contracts.currency_id',
            'project_contracts.date',
            'project_contracts.returned',
            'project_contracts.files',
            'project_contracts.note',
            'project_contracts.created_at',
            'project_contracts.updated_at',
            'currencies.name as currency_name',
            'currencies.code as currency_code',
            'currencies.symbol as currency_symbol'
        ])
            ->leftJoin('currencies', 'project_contracts.currency_id', '=', 'currencies.id')
            ->with(['currency:id,name,code,symbol']);
    }

    /**
     * Применить фильтрацию по компании через связь с проектом
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyCompanyFilter($query)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->whereHas('project', function ($q) use ($companyId) {
                $q->where('projects.company_id', $companyId);
            });
        } else {
            $query->whereHas('project', function ($q) {
                $q->whereNull('projects.company_id');
            });
        }
        return $query;
    }

    /**
     * Получить контракты проекта с пагинацией
     *
     * @param int $projectId ID проекта
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @param string|null $search Поисковый запрос
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getProjectContractsWithPagination($projectId, $perPage = 20, $page = 1, $search = null)
    {
        $cacheKey = $this->generateCacheKey('project_contracts_paginated', [$projectId, $perPage, $page, $search]);

        return CacheService::getPaginatedData($cacheKey, function () use ($projectId, $perPage, $search, $page) {
            $query = $this->getBaseQuery()
                ->where('project_contracts.project_id', $projectId);

            $this->applyCompanyFilter($query);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('project_contracts.number', 'like', "%{$search}%")
                        ->orWhere('project_contracts.amount', 'like', "%{$search}%")
                        ->orWhere('project_contracts.note', 'like', "%{$search}%");
                });
            }

            return $query->orderBy('project_contracts.date', 'desc')
                ->orderBy('project_contracts.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    /**
     * Получить все контракты проекта
     *
     * @param int $projectId ID проекта
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_contracts_all', [$projectId]);

        return CacheService::getReferenceData($cacheKey, function () use ($projectId) {
            $query = $this->getBaseQuery()
                ->where('project_contracts.project_id', $projectId);

            $this->applyCompanyFilter($query);

            return $query->orderBy('project_contracts.date', 'desc')
                ->orderBy('project_contracts.created_at', 'desc')
                ->get();
        });
    }

    /**
     * Создать контракт проекта
     *
     * @param array $data Данные контракта
     * @return ProjectContract
     * @throws \Exception
     */
    public function createContract(array $data): ProjectContract
    {
        DB::beginTransaction();
        try {
            $project = Project::findOrFail($data['project_id']);

            $contract = new ProjectContract();
            $contract->project_id = $data['project_id'];
            $contract->number = $data['number'];
            $contract->amount = $data['amount'];
            $contract->currency_id = $data['currency_id'] ?? null;
            $contract->date = $data['date'];
            $contract->returned = $data['returned'] ?? false;
            $contract->files = $data['files'] ?? [];
            $contract->note = $data['note'] ?? null;
            $contract->save();

            DB::commit();

            $this->invalidateProjectContractsCache($data['project_id']);

            return $contract;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Обновить контракт проекта
     *
     * @param int $id ID контракта
     * @param array $data Данные для обновления
     * @return ProjectContract
     * @throws \Exception
     */
    public function updateContract(int $id, array $data): ProjectContract
    {
        DB::beginTransaction();
        try {
            $contract = ProjectContract::findOrFail($id);

            $contract->number = $data['number'];
            $contract->amount = $data['amount'];
            $contract->currency_id = $data['currency_id'] ?? null;
            $contract->date = $data['date'];
            $contract->returned = $data['returned'] ?? false;
            $contract->note = $data['note'] ?? null;

            if (isset($data['files']) && is_array($data['files'])) {
                $contract->files = $data['files'];
            }

            $contract->save();

            DB::commit();

            $this->invalidateProjectContractsCache($contract->project_id);

            return $contract;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Найти контракт по ID
     *
     * @param int $id ID контракта
     * @return ProjectContract|null
     */
    public function findContract(int $id): ?ProjectContract
    {
        $cacheKey = $this->generateCacheKey('project_contract_item', [$id]);

        return CacheService::remember($cacheKey, function () use ($id) {
            $query = ProjectContract::with([
                'project:id,name,company_id',
                'currency:id,name,code,symbol'
            ])->where('id', $id);

            $this->applyCompanyFilter($query);

            return $query->first();
        }, 1800);
    }

    /**
     * Удалить контракт проекта
     *
     * @param int $id ID контракта
     * @return bool
     * @throws \Exception
     */
    public function deleteContract(int $id): bool
    {
        DB::beginTransaction();
        try {
            $contract = ProjectContract::findOrFail($id);

            $projectId = $contract->project_id;
            $contract->delete();

            DB::commit();

            $this->invalidateProjectContractsCache($projectId);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Инвалидация кэша контрактов проекта
     *
     * @param int $projectId ID проекта
     * @return void
     */
    private function invalidateProjectContractsCache(int $projectId): void
    {
        $companyId = $this->getCurrentCompanyId() ?? 'default';
        CacheService::forget($this->generateCacheKey('project_contracts_all', [$projectId]));
        CacheService::invalidateByLike("%project_contracts_paginated_{$projectId}_{$companyId}%");
        CacheService::invalidateByLike("%project_contract_item_{$companyId}%");
    }

}
