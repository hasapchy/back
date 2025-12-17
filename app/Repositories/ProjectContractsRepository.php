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
            'currencies.symbol as currency_symbol'
        ])
            ->leftJoin('currencies', 'project_contracts.currency_id', '=', 'currencies.id');
    }

    /**
     * Применить фильтрацию по компании через связь с проектом
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $companyId ID компании (игнорируется, так как в project_contracts нет company_id)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyCompanyFilter($query, $companyId = null)
    {
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
    public function getItemsWithPagination($projectId, $perPage = 20, $page = 1, $search = null)
    {
        $cacheKey = $this->generateCacheKey('project_contracts_paginated', [$projectId, $perPage, $page, $search]);

        return CacheService::getPaginatedData($cacheKey, function () use ($projectId, $perPage, $search, $page) {
            $query = $this->getBaseQuery()
                ->where('project_contracts.project_id', $projectId);

            $this->applyCompanyFilter($query);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('project_contracts.number', 'like', "%{$search}%")
                        ->orWhere('project_contracts.amount', 'like', "%{$search}%");
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
        return DB::transaction(function () use ($data) {
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

            $this->invalidateProjectContractsCache($data['project_id']);

            return $contract;
        });
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
        return DB::transaction(function () use ($id, $data) {
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

            $this->invalidateProjectContractsCache($contract->project_id, $id);

            return $contract;
        });
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
                'currency:id,name,symbol'
            ])->where('id', $id);

            $this->applyCompanyFilter($query);

            return $query->first();
        }, $this->getCacheTTL('item'));
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
        return DB::transaction(function () use ($id) {
            $contract = ProjectContract::findOrFail($id);

            $projectId = $contract->project_id;
            $contract->delete();

            $this->invalidateProjectContractsCache($projectId, $id);

            return true;
        });
    }

    /**
     * Инвалидация кэша контрактов проекта
     *
     * @param int $projectId ID проекта
     * @param int|null $contractId ID контракта (опционально, для инвалидации конкретного контракта)
     * @return void
     */
    private function invalidateProjectContractsCache(int $projectId, ?int $contractId = null): void
    {
        if ($contractId !== null) {
            CacheService::forget($this->generateCacheKey('project_contract_item', [$contractId]));
        }

        CacheService::invalidateProjectsCache();
    }

}
