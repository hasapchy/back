<?php

namespace App\Repositories;

use App\Models\OrderStatus;
use App\Services\CacheService;

class OrderStatusRepository extends BaseRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $cacheKey = $this->generateCacheKey('order_statuses_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function() use ($perPage) {
            return OrderStatus::with('category')->paginate($perPage);
        }, 1);
    }

    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('order_statuses_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function() {
            return OrderStatus::with('category')->get();
        });
    }

    public function createItem($data)
    {
        $item = OrderStatus::create($data);
        CacheService::invalidateOrderStatusesCache();
        return $item;
    }

    public function updateItem($id, $data)
    {
        $item = OrderStatus::findOrFail($id);
        $item->update($data);
        CacheService::invalidateOrderStatusesCache();
        return $item;
    }

    public function deleteItem($id)
    {
        $item = OrderStatus::findOrFail($id);
        $item->delete();
        CacheService::invalidateOrderStatusesCache();
        return true;
    }
}
