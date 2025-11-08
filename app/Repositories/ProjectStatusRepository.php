<?php

namespace App\Repositories;

use App\Models\ProjectStatus;
use App\Services\CacheService;

class ProjectStatusRepository extends BaseRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $cacheKey = $this->generateCacheKey('project_statuses_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function() use ($perPage) {
            return ProjectStatus::with('user')->paginate($perPage);
        }, 1);
    }

    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('project_statuses_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function() {
            return ProjectStatus::with('user')->get();
        });
    }

    public function createItem($data)
    {
        $item = ProjectStatus::create($data);
        CacheService::invalidateProjectStatusesCache();
        return $item;
    }

    public function updateItem($id, $data)
    {
        $item = ProjectStatus::findOrFail($id);
        $item->update($data);
        CacheService::invalidateProjectStatusesCache();
        return $item;
    }

    public function deleteItem($id)
    {
        $item = ProjectStatus::findOrFail($id);
        $item->delete();
        CacheService::invalidateProjectStatusesCache();
        return true;
    }
}

