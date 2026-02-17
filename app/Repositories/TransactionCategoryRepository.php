<?php

namespace App\Repositories;

use App\Models\TransactionCategory;
use App\Services\CacheService;

class TransactionCategoryRepository extends BaseRepository
{
    /**
     * Получить категории транзакций с пагинацией
     *
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('transaction_categories_paginated', [$perPage, $page]);

        return CacheService::getPaginatedData($cacheKey, function() use ($perPage, $page) {
            return TransactionCategory::with('creator')->paginate($perPage, ['*'], 'page', (int) $page);
        }, 1);
    }

    /**
     * Получить все категории транзакций
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems()
    {
        $cacheKey = $this->generateCacheKey('transaction_categories_all', []);

        return CacheService::getReferenceData($cacheKey, function() {
            return TransactionCategory::with('creator')->get();
        });
    }

    /**
     * Создать категорию транзакций
     *
     * @param array $data Данные категории
     * @return bool
     */
    public function createItem($data)
    {
        $item = new TransactionCategory();
        $item->name = $data['name'];
        $item->type = $data['type'];
        $item->creator_id = $data['creator_id'];
        $item->save();
        CacheService::invalidateTransactionCategoriesCache();
        return true;
    }

    /**
     * Обновить категорию транзакций
     *
     * @param int $id ID категории
     * @param array $data Данные для обновления
     * @return bool
     * @throws \Exception
     */
    public function updateItem($id, $data)
    {
        $item = TransactionCategory::findOrFail($id);

        if (!$item->canBeEdited()) {
            throw new \Exception('Нельзя редактировать системную категорию: ' . $item->name);
        }

        $item->name = $data['name'];
        $item->type = $data['type'];
        $item->creator_id = $data['creator_id'];
        $item->save();
        CacheService::invalidateTransactionCategoriesCache();
        return true;
    }

    /**
     * Удалить категорию транзакций
     *
     * @param int $id ID категории
     * @return bool
     * @throws \Exception
     */
    public function deleteItem($id)
    {
        $item = TransactionCategory::findOrFail($id);

        if (!$item->canBeDeleted()) {
            throw new \Exception('Нельзя удалить системную категорию: ' . $item->name);
        }

        $item->delete();
        CacheService::invalidateTransactionCategoriesCache();
        return true;
    }
}
