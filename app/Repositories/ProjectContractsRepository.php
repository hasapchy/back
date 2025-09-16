<?php

namespace App\Repositories;

use App\Models\ProjectContract;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class ProjectContractsRepository
{
    // Получение контрактов проекта с пагинацией
    public function getProjectContractsWithPagination($projectId, $perPage = 20, $page = 1, $search = null)
    {
        $cacheKey = "project_contracts_paginated_{$projectId}_{$perPage}_{$page}_{$search}";

        return CacheService::getPaginatedData($cacheKey, function () use ($projectId, $perPage, $search, $page) {
            $query = ProjectContract::select([
                'project_contracts.id',
                'project_contracts.project_id',
                'project_contracts.number',
                'project_contracts.amount',
                'project_contracts.currency_id',
                'project_contracts.date',
                'project_contracts.returned',
                'project_contracts.files',
                'project_contracts.created_at',
                'project_contracts.updated_at',
                'currencies.name as currency_name',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol'
            ])
                ->leftJoin('currencies', 'project_contracts.currency_id', '=', 'currencies.id')
                ->where('project_contracts.project_id', $projectId)
                ->with(['currency:id,name,code,symbol']);

            // Применяем поиск
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('project_contracts.number', 'like', "%{$search}%")
                        ->orWhere('project_contracts.amount', 'like', "%{$search}%");
                });
            }

            return $query->orderBy('project_contracts.date', 'desc')
                ->orderBy('project_contracts.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
        }, $page);
    }

    // Получение всех контрактов проекта
    public function getAllProjectContracts($projectId)
    {
        $cacheKey = "project_contracts_all_{$projectId}";

        return CacheService::remember($cacheKey, function () use ($projectId) {
            return ProjectContract::select([
                'project_contracts.id',
                'project_contracts.project_id',
                'project_contracts.number',
                'project_contracts.amount',
                'project_contracts.currency_id',
                'project_contracts.date',
                'project_contracts.returned',
                'project_contracts.files',
                'project_contracts.created_at',
                'project_contracts.updated_at',
                'currencies.name as currency_name',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol'
            ])
                ->leftJoin('currencies', 'project_contracts.currency_id', '=', 'currencies.id')
                ->where('project_contracts.project_id', $projectId)
                ->with(['currency:id,name,code,symbol'])
                ->orderBy('project_contracts.date', 'desc')
                ->orderBy('project_contracts.created_at', 'desc')
                ->get();
        }, 1800); // 30 минут
    }

    // Создание контракта
    public function createContract($data)
    {
        DB::beginTransaction();
        try {
            $contract = new ProjectContract();
            $contract->project_id = $data['project_id'];
            $contract->number = $data['number'];
            $contract->amount = $data['amount'];
            $contract->currency_id = $data['currency_id'] ?? null;
            $contract->date = $data['date'];
            $contract->returned = $data['returned'] ?? false;
            $contract->files = $data['files'] ?? [];
            $contract->save();

            DB::commit();

            // Инвалидируем кэш контрактов
            $this->invalidateProjectContractsCache($data['project_id']);

            return $contract;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // Обновление контракта
    public function updateContract($id, $data)
    {
        DB::beginTransaction();
        try {
            $contract = ProjectContract::find($id);
            if (!$contract) {
                throw new \Exception('Contract not found');
            }

            $contract->number = $data['number'];
            $contract->amount = $data['amount'];
            $contract->currency_id = $data['currency_id'] ?? null;
            $contract->date = $data['date'];
            $contract->returned = $data['returned'] ?? false;

            // Защита: если files переданы, убедись, что это массив
            if (isset($data['files']) && is_array($data['files'])) {
                $contract->files = $data['files'];
            }

            $contract->save();

            DB::commit();

            // Инвалидируем кэш контрактов
            $this->invalidateProjectContractsCache($contract->project_id);

            return $contract;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // Получение контракта по ID
    public function findContract($id)
    {
        $cacheKey = "project_contract_item_{$id}";

        return CacheService::remember($cacheKey, function () use ($id) {
            return ProjectContract::with([
                'project:id,name',
                'currency:id,name,code,symbol'
            ])->find($id);
        }, 1800); // 30 минут
    }

    // Удаление контракта
    public function deleteContract($id)
    {
        DB::beginTransaction();
        try {
            $contract = ProjectContract::find($id);
            if (!$contract) {
                return false;
            }

            $projectId = $contract->project_id;
            $contract->delete();

            DB::commit();

            // Инвалидируем кэш контрактов
            $this->invalidateProjectContractsCache($projectId);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Инвалидация кэша контрактов проекта
     */
    private function invalidateProjectContractsCache($projectId)
    {
        $keys = [
            "project_contracts_paginated_{$projectId}_*",
            "project_contracts_all_{$projectId}",
            "project_contract_item_*"
        ];

        foreach ($keys as $key) {
            if (str_contains($key, '*')) {
                \Illuminate\Support\Facades\Cache::flush();
                break;
            } else {
                \Illuminate\Support\Facades\Cache::forget($key);
            }
        }
    }

    /**
     * Инвалидация кэша конкретного контракта
     */
    public function invalidateContractCache($contractId)
    {
        \Illuminate\Support\Facades\Cache::forget("project_contract_item_{$contractId}");
    }
}
