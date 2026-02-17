<?php

namespace App\Repositories;

use App\Models\OrderStatusCategory;
use App\Services\CacheService;

class OrderStatusCategoryRepository extends BaseRepository
{
    /**
     * Получить категории статусов заказов с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('order_status_categories_paginated', [$userUuid, $perPage, $page]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $page) {
            $query = OrderStatusCategory::query();
            $this->applyOwnFilter($query, 'order_status_categories', 'order_status_categories', 'creator_id');

            return $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
        }, $page);
    }

    /**
     * Получить все категории статусов заказов
     *
     * @param  int  $userUuid  ID пользователя
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('order_status_categories_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function () {
            $query = OrderStatusCategory::query();
            $this->applyOwnFilter($query, 'order_status_categories', 'order_status_categories', 'creator_id');

            return $query->get();
        });
    }

    /**
     * Создать категорию статусов заказов
     *
     * @param  array  $data  Данные категории
     * @return OrderStatusCategory
     */
    public function createItem($data)
    {
        $item = OrderStatusCategory::create($data);
        CacheService::invalidateOrderStatusCategoriesCache();

        return $item;
    }

    /**
     * Обновить категорию статусов заказов
     *
     * @param  int  $id  ID категории
     * @param  array  $data  Данные для обновления
     * @return OrderStatusCategory
     */
    public function updateItem($id, $data)
    {
        $item = OrderStatusCategory::findOrFail($id);
        $item->update($data);
        CacheService::invalidateOrderStatusCategoriesCache();

        return $item;
    }

    /**
     * Удалить категорию статусов заказов
     *
     * @param  int  $id  ID категории
     * @return bool
     */
    public function deleteItem($id)
    {
        $item = OrderStatusCategory::findOrFail($id);
        $item->delete();
        CacheService::invalidateOrderStatusCategoriesCache();

        return true;
    }
}
