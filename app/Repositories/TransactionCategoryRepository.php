<?php

namespace App\Repositories;

use App\Models\TransactionCategory;

class TransactionCategoryRepository
{
    // Получение с пагинацией
    public function getItemsWithPagination($perPage = 20)
    {
        // Возвращаем все категории независимо от владельца
        return TransactionCategory::with('user')
            ->paginate($perPage);
    }

    // Получение всего списка без фильтрации по пользователю
    public function getAllItems()
    {
        return TransactionCategory::with('user')->get();
    }

    // Создание
    public function createItem($data)
    {
        $item = new TransactionCategory();
        $item->name = $data['name'];
        $item->type = $data['type'];
        $item->user_id = $data['user_id'];
        $item->save();
        return true;
    }

    // Обновление
    public function updateItem($id, $data)
    {
        $item = TransactionCategory::find($id);
        if (!$item) {
            return false;
        }

        if (!$item->canBeEdited()) {
            throw new \Exception('Нельзя редактировать системную категорию: ' . $item->name);
        }

        $item->name = $data['name'];
        $item->type = $data['type'];
        $item->user_id = $data['user_id'];
        $item->save();
        return true;
    }

    // Удаление
    public function deleteItem($id)
    {
        $item = TransactionCategory::find($id);
        if (!$item) {
            return false;
        }

        if (!$item->canBeDeleted()) {
            throw new \Exception('Нельзя удалить системную категорию: ' . $item->name);
        }

        $item->delete();
        return true;
    }
}
