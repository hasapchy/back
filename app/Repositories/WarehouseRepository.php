<?php

namespace App\Repositories;

use App\Models\Warehouse;

class WarehouseRepository
{
    // Получение складов с пагинацией
    public function getWarehousesWithPagination($userUuid, $perPage = 20)
    {
        return Warehouse::whereJsonContains('users', (string) $userUuid)
            ->orderBy('created_at', 'desc')->paginate($perPage);
    }

    // Получение списка всех складов
    public function getAllWarehouses($userUuid)
    {
        return Warehouse::whereJsonContains('users', (string) $userUuid)
            ->get();
    }

    // Создание склада с именем и массивом пользователей
    public function createWarehouse($name, array $users)
    {
        $warehouse = new Warehouse();
        $warehouse->name = $name;
        $warehouse->users = array_map('strval', $users);

        $warehouse->save();

        return true;
    }

    //  Обновление склада
    public function updateWarehouse($id, $name, array $users)
    {
        $warehouse = Warehouse::find($id);
        $warehouse->name = $name;
        $warehouse->users = $users;

        $warehouse->save();

        return true;
    }

    // Удаление склада
    public function deleteWarehouse($id)
    {
        $warehouse = Warehouse::find($id);
        $warehouse->delete();

        return true;
    }
}
