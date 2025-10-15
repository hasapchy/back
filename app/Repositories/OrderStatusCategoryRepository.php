<?php

namespace App\Repositories;

use App\Models\OrderStatusCategory;

class OrderStatusCategoryRepository
{
    // Пагинация
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $items = OrderStatusCategory::where('user_id', $userUuid)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
        return $items;
    }

    public function getAllItems($userUuid)
    {
        return OrderStatusCategory::where('user_id', $userUuid)->get();
    }

    public function createItem($data)
    {
        return OrderStatusCategory::create($data);
    }

    public function updateItem($id, $data)
    {
        $item = OrderStatusCategory::findOrFail($id);
        $item->update($data);
        return $item;
    }

    public function deleteItem($id)
    {
        $item = OrderStatusCategory::findOrFail($id);
        $item->delete();
        return true;
    }
}
