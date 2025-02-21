<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\Warehouse;

class CategoriesRepository
{
    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $items = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
            ->leftJoin('users as users', 'categories.user_id', '=', 'users.id')
            ->select('categories.*', 'parents.name as parent_name', 'users.name as user_name')
            ->whereJsonContains('categories.users', (string) $userUuid)
            ->paginate($perPage);
        return $items;
    }
    
    // Получение всего списка
    public function getAllItems($userUuid)
    {
        $items = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
            ->leftJoin('users as users', 'categories.user_id', '=', 'users.id')
            ->select('categories.*', 'parents.name as parent_name', 'users.name as user_name')
            ->whereJsonContains('categories.users', (string) $userUuid)
            ->get();
        return $items;
    }

    // Создание
    public function createItem($data)
    {
        $item = new Category();
        $item->name = $data['name'];
        $item->parent_id = $data['parent_id'];
        $item->user_id = $data['user_id'];
        $item->users = array_map('strval', $data['users']);
        $item->save();

        return true;
    }

    // Обновление
    public function updateItem($id, $data)
    {
        $item = Category::find($id);
        $item->name = $data['name'];
        $item->parent_id = $data['parent_id'];
        $item->user_id = $data['user_id'];
        $item->users = array_map('strval', $data['users']);
        $item->save();

        return true;
    }

    // Удаление
    public function deleteItem($id)
    {
        $item = Category::find($id);
        $item->delete();

        return true;
    }
}
