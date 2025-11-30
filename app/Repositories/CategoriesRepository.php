<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\CategoryUser;
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

        return CacheService::getPaginatedData($cacheKey, function() use ($userUuid, $perPage, $page) {
            /** @var \App\Models\User|null $user */
            $user = auth('api')->user();
            $isAdmin = $user && $user->is_admin;

            $query = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
                ->leftJoin('users as users', 'categories.user_id', '=', 'users.id')
                ->select('categories.*', 'parents.name as parent_name', 'users.name as user_name');

            if (!$isAdmin) {
                $query->whereHas('categoryUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                });
            }

            $query = $this->addCompanyFilterDirect($query, 'categories');

            return $query->with(['users:id,name,surname,email,position'])->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
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

        return CacheService::getReferenceData($cacheKey, function() use ($userUuid) {
            /** @var \App\Models\User|null $user */
            $user = auth('api')->user();
            $isAdmin = $user && $user->is_admin;

            $query = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
                ->leftJoin('users as users', 'categories.user_id', '=', 'users.id')
                ->select('categories.*', 'parents.name as parent_name', 'users.name as user_name');

            if (!$isAdmin) {
                $query->whereHas('categoryUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                });
            }

            $query = $this->addCompanyFilterDirect($query, 'categories');

            return $query->with(['users:id,name,surname,email,position'])->get();
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

        return CacheService::getReferenceData($cacheKey, function() use ($userUuid) {
            /** @var \App\Models\User|null $user */
            $user = auth('api')->user();
            $isAdmin = $user && $user->is_admin;

            $query = Category::leftJoin('users as users', 'categories.user_id', '=', 'users.id')
                ->select('categories.*', 'users.name as user_name')
                ->whereNull('categories.parent_id')
                ->whereHas('children');

            if (!$isAdmin) {
                $query->whereHas('categoryUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                });
            }

            $query = $this->addCompanyFilterDirect($query, 'categories');

            return $query->with(['users:id,name,surname,email,position'])->get();
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
        $item->parent_id = ($data['parent_id'] === '' || $data['parent_id'] === null) ? null : (int) $data['parent_id'];
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
        $item->parent_id = ($data['parent_id'] === '' || $data['parent_id'] === null) ? null : (int) $data['parent_id'];
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
     * Синхронизировать пользователей категории
     *
     * @param int $categoryId ID категории
     * @param array $userIds Массив ID пользователей
     * @return void
     * @throws \Exception Если пытаются удалить всех пользователей
     */
    private function syncUsers(int $categoryId, array $userIds)
    {
        if (empty($userIds) || !is_array($userIds)) {
            throw new \Exception('Категория должна иметь хотя бы одного пользователя');
        }

        $insertData = [];
        foreach ($userIds as $userId) {
            if (!empty($userId)) {
                $insertData[] = [
                    'category_id' => $categoryId,
                    'user_id' => (int) $userId,
                ];
            }
        }

        if (empty($insertData)) {
            throw new \Exception('Категория должна иметь хотя бы одного пользователя');
        }

        CategoryUser::where('category_id', $categoryId)->delete();
        CategoryUser::insert($insertData);
    }
}
