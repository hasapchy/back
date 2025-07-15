<?php

namespace App\Repositories;

use App\Models\OrderCategory;

class OrderCategoryRepository
{
    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        return OrderCategory::where('user_id', $userUuid)
            ->paginate($perPage);
    }

    // Получение всего списка
    public function getAllItems($userUuid)
    {
        return OrderCategory::where('user_id', $userUuid)
            ->get();
    }

    // Создание
    public function createItem($data)
    {
        $item = new OrderCategory();
        $item->name = $data['name'];
        $item->user_id = $data['user_id'];
        $item->save();
        return true;
    }

    // Обновление
    public function updateItem($id, $data)
    {
        $item = OrderCategory::find($id);
        $item->name = $data['name'];
        $item->user_id = $data['user_id'];
        $item->save();
        return true;
    }

    // Удаление
    public function deleteItem($id)
    {
        $item = OrderCategory::find($id);
        $item->delete();
        return true;
    }
}
