<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\CategoryUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CacheService;

class CategoriesRepository extends BaseRepository
{
    /**
     * Получить категории с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('categories_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page) {
            // users в central — join по users в tenant даёт 500; user_name добавляем в PHP
            $query = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
                ->select('categories.*', 'parents.name as parent_name');

            $this->applyUserFilter($query, $userUuid);
            $query = $this->addCompanyFilterDirect($query, 'categories');

            /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator */
            $paginator = $query->paginate($perPage, ['*'], 'page', (int) $page);
            $this->attachUserNamesToCategories($paginator->getCollection());

            return $paginator;
        }, (int) $page);
    }

    /**
     * Получить все категории пользователя
     *
     * @param int $userUuid ID пользователя
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('categories_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function () use ($userUuid) {
            // users в central — join по users в tenant даёт 500; user_name добавляем в PHP
            $query = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
                ->select('categories.*', 'parents.name as parent_name');

            $this->applyUserFilter($query, $userUuid);
            $query = $this->addCompanyFilterDirect($query, 'categories');

            $items = $query->get();
            $this->attachUserNamesToCategories($items);

            return $items;
        });
    }

    /**
     * Получить родительские категории (с дочерними)
     *
     * @param int $userUuid ID пользователя
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getParentCategories($userUuid)
    {
        $cacheKey = $this->generateCacheKey('categories_parents', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function () use ($userUuid) {
            // users в central — join по users в tenant даёт 500; user_name добавляем в PHP
            $query = Category::query()
                ->whereNull('categories.parent_id')
                ->whereHas('children');

            $this->applyUserFilter($query, $userUuid);
            $query = $this->addCompanyFilterDirect($query, 'categories');

            $items = $query->get();
            $this->attachUserNamesToCategories($items);

            return $items;
        });
    }

    /**
     * Создать категорию
     *
     * @param array $data Данные категории
     * @return bool
     */
    public function createItem($data)
    {
        $companyId = $this->getCurrentCompanyId();

        $item = new Category();
        $item->name = $data['name'];
        $item->parent_id = empty($data['parent_id']) ? null : (int) $data['parent_id'];
        $item->user_id = $data['user_id'];
        $item->company_id = $companyId;
        $item->save();

        $this->syncUsers($item->id, $data['users'] ?? []);

        CacheService::invalidateCategoriesCache();

        return true;
    }

    /**
     * Обновить категорию
     *
     * @param int $id ID категории
     * @param array $data Данные для обновления
     * @return bool
     */
    public function updateItem($id, $data)
    {
        $companyId = $this->getCurrentCompanyId();

        $item = Category::findOrFail($id);
        $item->name = $data['name'];
        $item->parent_id = empty($data['parent_id']) ? null : (int) $data['parent_id'];
        $item->user_id = $data['user_id'];
        $item->company_id = $companyId;
        $item->save();

        $this->syncUsers($id, $data['users'] ?? []);

        CacheService::invalidateCategoriesCache();

        return true;
    }

    /**
     * Удалить категорию
     *
     * @param int $id ID категории
     * @return bool
     */
    public function deleteItem($id)
    {
        $item = Category::findOrFail($id);
        $item->delete();

        CacheService::invalidateCategoriesCache();

        return true;
    }

    /**
     * Добавить user_name категориям из central.users (tenant-запрос не может джойнить users).
     *
     * @param \Illuminate\Support\Collection $categories
     * @return void
     */
    private function attachUserNamesToCategories($categories)
    {
        $userIds = $categories->pluck('user_id')->filter()->unique()->values()->all();
        if (empty($userIds)) {
            foreach ($categories as $c) {
                $c->user_name = null;
            }
            return;
        }
        $names = User::on('central')->whereIn('id', $userIds)->pluck('name', 'id');
        foreach ($categories as $c) {
            $c->user_name = $c->user_id ? ($names[$c->user_id] ?? null) : null;
        }
    }

    /**
     * Применить фильтр пользователя к запросу категорий
     *
     * Фильтрует категории по правам доступа пользователя через связь category_users
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param int $userUuid ID пользователя для фильтрации
     * @return void
     */
    private function applyUserFilter($query, $userUuid)
    {
        if ($this->shouldApplyUserFilter('categories')) {
            $query->join('category_users', 'categories.id', '=', 'category_users.category_id')
                ->where('category_users.user_id', $userUuid)
                ->distinct();
        }
    }

    /**
     * Синхронизировать пользователей категории
     *
     * @param int $categoryId ID категории
     * @param array $userIds Массив ID пользователей
     * @return void
     * @throws \Exception Если пытаются удалить всех пользователей
     */
    private function syncUsers(int $categoryId, array $userIds)
    {
        $this->syncManyToManyUsers(
            CategoryUser::class,
            'category_id',
            $categoryId,
            $userIds,
            [
                'require_at_least_one' => true,
                'filter_empty' => true,
                'error_message' => 'Категория должна иметь хотя бы одного пользователя'
            ]
        );
    }
}
