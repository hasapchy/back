<?php

namespace App\Repositories;

use App\Models\OrderStatus;

class OrderStatusRepository
{
    // Пагинация
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        // Статусы по своим категориям, категории — только для своих пользователей
        $items = OrderStatus::with('category')
            ->whereHas('category', function ($q) use ($userUuid) {
                $q->where('user_id', $userUuid);
            })
            ->paginate($perPage);
        return $items;
    }

    // Все статусы
    public function getAllItems($userUuid)
    {
        return OrderStatus::with('category')
            ->whereHas('category', function ($q) use ($userUuid) {
                $q->where('user_id', $userUuid);
            })->get();
    }

    public function createItem($data)
    {
        return OrderStatus::create($data);
    }

    public function updateItem($id, $data)
    {
        $item = OrderStatus::findOrFail($id);
        $item->update($data);
        return $item;
    }

    public function deleteItem($id)
    {
        $item = OrderStatus::findOrFail($id);
        $item->delete();
        return true;
    }
}
