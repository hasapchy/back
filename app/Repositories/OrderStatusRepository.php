<?php

namespace App\Repositories;

use App\Models\OrderStatus;

class OrderStatusRepository
{
    // Пагинация
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        // Возвращаем все статусы независимо от владельца категории
        $items = OrderStatus::with('category')
            ->paginate($perPage);
        return $items;
    }

    // Все статусы
    public function getAllItems($userUuid)
    {
        // Возвращаем все статусы независимо от владельца категории
        return OrderStatus::with('category')->get();
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
