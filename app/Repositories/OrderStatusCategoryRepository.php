<?php

namespace App\Repositories;

use App\Models\OrderStatusCategory;
use App\Services\CacheService;

class OrderStatusCategoryRepository extends BaseRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $cacheKey = $this->generateCacheKey('order_status_categories_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function() use ($userUuid, $perPage) {
            return OrderStatusCategory::where('user_id', $userUuid)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
        }, 1);
    }

    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('order_status_categories_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function() use ($userUuid) {
            return OrderStatusCategory::where('user_id', $userUuid)->get();
        });
    }

    public function createItem($data)
    {
        $item = OrderStatusCategory::create($data);
        CacheService::invalidateOrderStatusCategoriesCache();
        return $item;
    }

    public function updateItem($id, $data)
    {
        $item = OrderStatusCategory::findOrFail($id);
        $item->update($data);
        CacheService::invalidateOrderStatusCategoriesCache();
        return $item;
    }

    public function deleteItem($id)
    {
        $item = OrderStatusCategory::findOrFail($id);
        $item->delete();
        CacheService::invalidateOrderStatusCategoriesCache();
        return true;
    }
}
