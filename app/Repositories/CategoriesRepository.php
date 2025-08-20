<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\CategoryUser;
use App\Models\Warehouse;

class CategoriesRepository
{
    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $items = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
            ->leftJoin('users as users', 'categories.user_id', '=', 'users.id')
            ->select('categories.*', 'parents.name as parent_name', 'users.name as user_name')
            ->whereHas('categoryUsers', function($query) use ($userUuid) {
                $query->where('user_id', $userUuid);
            })->with('users')->paginate($perPage);
        return $items;
    }

    // Получение всего списка
    public function getAllItems($userUuid)
    {
        $items = Category::leftJoin('categories as parents', 'categories.parent_id', '=', 'parents.id')
            ->leftJoin('users as users', 'categories.user_id', '=', 'users.id')
            ->select('categories.*', 'parents.name as parent_name', 'users.name as user_name')
            ->whereHas('categoryUsers', function($query) use ($userUuid) {
                $query->where('user_id', $userUuid);
            })->with('users')->get();
        return $items;
    }

    // Создание
    public function createItem($data)
    {
        $item = new Category();
        $item->name = $data['name'];
        $item->parent_id = $data['parent_id'];
        $item->user_id = $data['user_id'];
        $item->save();

        // Создаем связи с пользователями
        foreach ($data['users'] as $userId) {
            CategoryUser::create([
                'category_id' => $item->id,
                'user_id' => $userId
            ]);
        }

        return true;
    }

    // Обновление
    public function updateItem($id, $data)
    {
        $item = Category::find($id);
        $item->name = $data['name'];
        $item->parent_id = $data['parent_id'];
        $item->user_id = $data['user_id'];
        $item->save();

        // Удаляем старые связи
        CategoryUser::where('category_id', $id)->delete();

        // Создаем новые связи
        foreach ($data['users'] as $userId) {
            CategoryUser::create([
                'category_id' => $id,
                'user_id' => $userId
            ]);
        }

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
