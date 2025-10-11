<?php

namespace App\Repositories;

use App\Models\ProjectStatus;
use App\Services\CacheService;

class ProjectStatusRepository
{
    // Пагинация
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        // Возвращаем все статусы независимо от владельца
        $items = ProjectStatus::with('user')
            ->paginate($perPage);
        return $items;
    }

    // Все статусы
    public function getAllItems($userUuid)
    {
        // Кэшируем справочник статусов проектов на 2 часа
        return CacheService::getReferenceData('project_statuses_all', function() {
            return ProjectStatus::with('user')->get();
        });
    }

    public function createItem($data)
    {
        return ProjectStatus::create($data);
    }

    public function updateItem($id, $data)
    {
        $item = ProjectStatus::findOrFail($id);
        $item->update($data);
        return $item;
    }

    public function deleteItem($id)
    {
        $item = ProjectStatus::findOrFail($id);
        $item->delete();
        return true;
    }
}

