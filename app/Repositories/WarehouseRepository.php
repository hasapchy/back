<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Models\WhUser;

class WarehouseRepository
{
    // Получение складов с пагинацией
    public function getWarehousesWithPagination($userUuid, $perPage = 20)
    {
        return Warehouse::whereHas('whUsers', function($query) use ($userUuid) {
            $query->where('user_id', $userUuid);
        })->with('users')->orderBy('created_at', 'desc')->paginate($perPage);
    }

    // Получение списка всех складов
    public function getAllWarehouses($userUuid)
    {
        return Warehouse::whereHas('whUsers', function($query) use ($userUuid) {
            $query->where('user_id', $userUuid);
        })->with('users')->get();
    }

    // Создание склада с именем и массивом пользователей
    public function createItem($name, array $users)
    {
        $warehouse = new Warehouse();
        $warehouse->name = $name;
        $warehouse->save();

        // Создаем связи с пользователями
        foreach ($users as $userId) {
            WhUser::create([
                'warehouse_id' => $warehouse->id,
                'user_id' => $userId
            ]);
        }

        return true;
    }

    //  Обновление склада
    public function updateItem($id, $name, array $users)
    {
        $warehouse = Warehouse::find($id);
        $warehouse->name = $name;
        $warehouse->save();

        // Удаляем старые связи
        WhUser::where('warehouse_id', $id)->delete();

        // Создаем новые связи
        foreach ($users as $userId) {
            WhUser::create([
                'warehouse_id' => $id,
                'user_id' => $userId
            ]);
        }

        return true;
    }

    // Удаление склада
    public function deleteItem($id)
    {
        $warehouse = Warehouse::find($id);
        $warehouse->delete();

        return true;
    }
}
