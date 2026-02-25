<?php

namespace App\Repositories;

use App\Models\Template;
use App\Services\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class TransactionTemplateRepository extends BaseRepository
{
    /**
     * @return array<string>
     */
    private function getBaseRelations(): array
    {
        return [
            'creator:id,name,surname',
            'cashRegister:id,name,currency_id,company_id',
            'currency:id,symbol,name',
            'category:id,name,type',
            'project:id,name',
            'client:id,first_name,last_name,patronymic',
        ];
    }

    /**
     * @param int $perPage
     * @param int $page
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator
     */
    public function getItemsWithPagination($perPage = 20, $page = 1, array $filters = []): LengthAwarePaginator
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $filtersKey = !empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('transaction_templates_paginated', [
            $perPage, $filtersKey, $currentUser?->id, $companyId,
        ]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $page, $filters) {
            $query = Template::query()
                ->with($this->getBaseRelations());

            $query = $this->addCompanyFilterThroughRelation($query, 'cashRegister', 'cash_registers');
            $this->applyOwnFilter($query, 'transaction_templates', 'templates', 'creator_id');
            $this->applyFilters($query, $filters);

            return $query->orderBy('templates.created_at', 'desc')->paginate($perPage, ['templates.*'], 'page', $page);
        }, (int) $page);
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection
     */
    public function getAllItems(array $filters = []): Collection
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $filtersKey = !empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('transaction_templates_all', [
            $filtersKey, $currentUser?->id, $companyId,
        ]);

        return CacheService::getReferenceData($cacheKey, function () use ($filters) {
            $query = Template::query()
                ->with($this->getBaseRelations());

            $query = $this->addCompanyFilterThroughRelation($query, 'cashRegister', 'cash_registers');
            $this->applyOwnFilter($query, 'transaction_templates', 'templates', 'creator_id');
            $this->applyFilters($query, $filters);

            return $query->orderBy('templates.created_at', 'desc')->get();
        });
    }

    /**
     * @param int $id
     * @return Template|null
     */
    public function getItemById(int $id): ?Template
    {
        $query = Template::query()
            ->with($this->getBaseRelations())
            ->where('templates.id', $id);

        $query = $this->addCompanyFilterThroughRelation($query, 'cashRegister', 'cash_registers');
        $this->applyOwnFilter($query, 'transaction_templates', 'templates', 'creator_id');

        return $query->first();
    }

    /**
     * @param array<string, mixed> $data
     * @return Template
     */
    public function createItem(array $data): Template
    {
        $item = Template::create($data);
        CacheService::invalidateTransactionTemplatesCache();
        return $item->load($this->getBaseRelations());
    }

    /**
     * @param int $id
     * @param array<string, mixed> $data
     * @return Template
     */
    public function updateItem(int $id, array $data): Template
    {
        $item = Template::findOrFail($id);
        $item->update($data);
        CacheService::invalidateTransactionTemplatesCache();
        return $item->load($this->getBaseRelations());
    }

    /**
     * @param int $id
     * @return bool
     */
    public function deleteItem(int $id): bool
    {
        Template::findOrFail($id)->delete();
        CacheService::invalidateTransactionTemplatesCache();
        return true;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array<string, mixed> $filters
     * @return void
     */
    private function applyFilters($query, array $filters): void
    {
        $query->when(isset($filters['cash_id']), fn ($q) => $q->where('templates.cash_id', $filters['cash_id']));
        $query->when(isset($filters['type']) && $filters['type'] !== '' && $filters['type'] !== null, fn ($q) => $q->where('templates.type', $filters['type']));
        $query->when(!empty($filters['search']), function ($q) use ($filters) {
            $search = trim($filters['search']);
            $q->where(function ($q2) use ($search) {
                $q2->where('templates.name', 'like', "%{$search}%")
                    ->orWhere('templates.note', 'like', "%{$search}%");
            });
        });
    }
}
