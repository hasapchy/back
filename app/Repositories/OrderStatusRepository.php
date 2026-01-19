<?php

namespace App\Repositories;

use App\Models\OrderStatus;
use App\Services\CacheService;

class OrderStatusRepository extends BaseRepository
{
    /**
     * Получить статусы заказов с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('order_statuses_paginated', [$userUuid, $perPage, $page]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $page) {
            return OrderStatus::with('category')->paginate($perPage, ['*'], 'page', $page);
        }, $page);
    }

    /**
     * Получить все статусы заказов
     *
     * @param  int  $userUuid  ID пользователя
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('order_statuses_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function () {
            return OrderStatus::with('category')->get();
        });
    }

    /**
     * Создать статус заказа
     *
     * @param  array  $data  Данные статуса
     * @return OrderStatus
     */
    public function createItem($data)
    {
        $item = OrderStatus::create($data);
        CacheService::invalidateOrderStatusesCache();

        return $item;
    }

    /**
     * Обновить статус заказа
     *
     * @param  int  $id  ID статуса
     * @param  array  $data  Данные для обновления
     * @return OrderStatus
     */
    public function updateItem($id, $data)
    {
        $item = OrderStatus::findOrFail($id);
        $item->update($data);
        CacheService::invalidateOrderStatusesCache();

        return $item;
    }

    /**
     * Удалить статус заказа
     *
     * @param  int  $id  ID статуса
     * @return bool
     */
    public function deleteItem($id)
    {
        $item = OrderStatus::findOrFail($id);
        $item->delete();
        CacheService::invalidateOrderStatusesCache();

        return true;
    }
}
