<?php

namespace App\Repositories;

use App\Models\ProjectContract;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class ProjectContractsRepository extends BaseRepository
{
    public function getProjectContractsWithPagination($projectId, $perPage = 20, $page = 1, $search = null)
    {
        $cacheKey = $this->generateCacheKey('project_contracts_paginated', [$projectId, $perPage, $page, $search]);

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
                'project_contracts.note',
                'project_contracts.created_at',
                'project_contracts.updated_at',
                'currencies.name as currency_name',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol'
            ])
                ->leftJoin('currencies', 'project_contracts.currency_id', '=', 'currencies.id')
                ->where('project_contracts.project_id', $projectId)
                ->with(['currency:id,name,code,symbol']);

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

    public function getAllItems($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_contracts_all', [$projectId]);

        return CacheService::getReferenceData($cacheKey, function () use ($projectId) {
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
                ->where('project_contracts.project_id', $projectId)
                ->with(['currency:id,name,code,symbol'])
                ->orderBy('project_contracts.date', 'desc')
                ->orderBy('project_contracts.created_at', 'desc')
                ->get();
        });
    }

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

    public function findContract($id)
    {
        $cacheKey = $this->generateCacheKey('project_contract_item', [$id]);

        return CacheService::remember($cacheKey, function () use ($id) {
            return ProjectContract::with([
                'project:id,name',
                'currency:id,name,code,symbol'
            ])->find($id);
        }, 1800);
    }

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
        $companyId = $this->getCurrentCompanyId() ?? 'default';
        \Illuminate\Support\Facades\Cache::forget($this->generateCacheKey('project_contracts_all', [$projectId]));
        CacheService::invalidateByLike("%project_contracts_paginated_{$projectId}_{$companyId}%");
        CacheService::invalidateByLike("%project_contract_item_{$companyId}%");
    }

    public function invalidateContractCache($contractId)
    {
        \Illuminate\Support\Facades\Cache::forget($this->generateCacheKey('project_contract_item', [$contractId]));
    }
}
