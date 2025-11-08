<?php

namespace App\Repositories;

use App\Models\TransactionCategory;
use App\Services\CacheService;

class TransactionCategoryRepository extends BaseRepository
{
    public function getItemsWithPagination($perPage = 20)
    {
        $cacheKey = $this->generateCacheKey('transaction_categories_paginated', [$perPage]);

        return CacheService::getPaginatedData($cacheKey, function() use ($perPage) {
            return TransactionCategory::with('user')->paginate($perPage);
        }, 1);
    }

    public function getAllItems()
    {
        $cacheKey = $this->generateCacheKey('transaction_categories_all', []);

        return CacheService::getReferenceData($cacheKey, function() {
            return TransactionCategory::with('user')->get();
        });
    }

    public function createItem($data)
    {
        $item = new TransactionCategory();
        $item->name = $data['name'];
        $item->type = $data['type'];
        $item->user_id = $data['user_id'];
        $item->save();
        CacheService::invalidateTransactionCategoriesCache();
        return true;
    }

    public function updateItem($id, $data)
    {
        $item = TransactionCategory::find($id);
        if (!$item) {
            return false;
        }

        if (!$item->canBeEdited()) {
            throw new \Exception('Нельзя редактировать системную категорию: ' . $item->name);
        }

        $item->name = $data['name'];
        $item->type = $data['type'];
        $item->user_id = $data['user_id'];
        $item->save();
        CacheService::invalidateTransactionCategoriesCache();
        return true;
    }

    public function deleteItem($id)
    {
        $item = TransactionCategory::find($id);
        if (!$item) {
            return false;
        }

        if (!$item->canBeDeleted()) {
            throw new \Exception('Нельзя удалить системную категорию: ' . $item->name);
        }

        $item->delete();
        CacheService::invalidateTransactionCategoriesCache();
        return true;
    }
}
