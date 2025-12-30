<?php

namespace App\Repositories;

use App\Models\ProjectTransaction;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class ProjectTransactionsRepository extends BaseRepository
{
    public function getItemsWithPagination($projectId, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('project_transactions_paginated', [$projectId, $perPage, $page]);

        return CacheService::getPaginatedData($cacheKey, function () use ($projectId, $perPage, $page) {
            $query = ProjectTransaction::with([
                'user:id,name',
                'currency:id,name,symbol',
                'category:id,name'
            ])
                ->where('project_id', $projectId)
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc');

            return $query->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    public function getAllItems($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_transactions_all', [$projectId]);

        return CacheService::getReferenceData($cacheKey, function () use ($projectId) {
            return ProjectTransaction::with([
                'user:id,name',
                'currency:id,name,symbol',
                'category:id,name'
            ])
                ->where('project_id', $projectId)
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    public function createItem(array $data): ProjectTransaction
    {
        return DB::transaction(function () use ($data) {
            $transaction = new ProjectTransaction();
            $transaction->project_id = $data['project_id'];
            $transaction->user_id = $data['user_id'];
            $transaction->type = $data['type'];
            $transaction->amount = $data['amount'];
            $transaction->currency_id = $data['currency_id'];
            $transaction->note = $data['note'] ?? null;
            $transaction->date = $data['date'];
            $transaction->save();

            $this->invalidateProjectTransactionsCache($data['project_id']);

            return $transaction->load(['user', 'currency', 'category']);
        });
    }

    public function updateItem(int $id, array $data): ProjectTransaction
    {
        return DB::transaction(function () use ($id, $data) {
            $transaction = ProjectTransaction::findOrFail($id);

            $transaction->type = $data['type'];
            $transaction->amount = $data['amount'];
            $transaction->currency_id = $data['currency_id'];
            $transaction->note = $data['note'] ?? null;
            $transaction->date = $data['date'];
            $transaction->save();

            $this->invalidateProjectTransactionsCache($transaction->project_id, $id);

            return $transaction->load(['user', 'currency', 'category']);
        });
    }

    public function findItem(int $id): ?ProjectTransaction
    {
        $cacheKey = $this->generateCacheKey('project_transaction_item', [$id]);

        return CacheService::remember($cacheKey, function () use ($id) {
            return ProjectTransaction::with([
                'project:id,name',
                'user:id,name',
                'currency:id,name,symbol',
                'category:id,name'
            ])->where('id', $id)->first();
        }, $this->getCacheTTL('item'));
    }

    public function deleteItem(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $transaction = ProjectTransaction::findOrFail($id);
            $projectId = $transaction->project_id;
            $transaction->delete();

            $this->invalidateProjectTransactionsCache($projectId, $id);

            return true;
        });
    }

    private function invalidateProjectTransactionsCache(int $projectId, ?int $transactionId = null): void
    {
        if ($transactionId !== null) {
            CacheService::forget($this->generateCacheKey('project_transaction_item', [$transactionId]));
        }

        CacheService::invalidateProjectsCache();
    }
}

