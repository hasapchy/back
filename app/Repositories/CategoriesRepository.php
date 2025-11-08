<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\CategoryUser;
use App\Models\Warehouse;
use App\Services\CacheService;

class CategoriesRepository extends BaseRepository
{


    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('categories_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function() use ($userUuid, $perPage, $page) {
            $query = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
                ->leftJoin('users as users', 'categories.user_id', '=', 'users.id')
                ->select('categories.*', 'parents.name as parent_name', 'users.name as user_name')
                ->whereHas('categoryUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                });

            $query = $this->addCompanyFilterDirect($query, 'categories');

            return $query->with('users')->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('categories_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function() use ($userUuid) {
            $query = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
                ->leftJoin('users as users', 'categories.user_id', '=', 'users.id')
                ->select('categories.*', 'parents.name as parent_name', 'users.name as user_name')
                ->whereHas('categoryUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                });

            $query = $this->addCompanyFilterDirect($query, 'categories');

            return $query->with('users')->get();
        });
    }

    public function getParentCategories($userUuid)
    {
        $cacheKey = $this->generateCacheKey('categories_parents', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function() use ($userUuid) {
            $query = Category::leftJoin('users as users', 'categories.user_id', '=', 'users.id')
                ->select('categories.*', 'users.name as user_name')
                ->whereNull('categories.parent_id')
                ->whereHas('categoryUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                })
                ->whereHas('children');

            $query = $this->addCompanyFilterDirect($query, 'categories');

            return $query->with('users')->get();
        });
    }

    public function createItem($data)
    {
        $companyId = $this->getCurrentCompanyId();

        $item = new Category();
        $item->name = $data['name'];
        $item->parent_id = $data['parent_id'];
        $item->user_id = $data['user_id'];
        $item->company_id = $companyId;
        $item->save();

        foreach ($data['users'] as $userId) {
            CategoryUser::create([
                'category_id' => $item->id,
                'user_id' => $userId
            ]);
        }

        CacheService::invalidateCategoriesCache();

        return true;
    }

    public function updateItem($id, $data)
    {
        $companyId = $this->getCurrentCompanyId();

        $item = Category::find($id);
        $item->name = $data['name'];
        $item->parent_id = $data['parent_id'];
        $item->user_id = $data['user_id'];
        $item->company_id = $companyId;
        $item->save();

        CategoryUser::where('category_id', $id)->delete();

        foreach ($data['users'] as $userId) {
            CategoryUser::create([
                'category_id' => $id,
                'user_id' => $userId
            ]);
        }

        CacheService::invalidateCategoriesCache();

        return true;
    }

    public function deleteItem($id)
    {
        $item = Category::find($id);
        $item->delete();

        CacheService::invalidateCategoriesCache();

        return true;
    }
}
