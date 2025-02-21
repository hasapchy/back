<?php

namespace App\Repositories;
use App\Models\Warehouse;

class WarehouseRepository
{
    // Получение складов с пагинацией
    public function getWarehousesWithPagination($perPage = 20)
    {
        return Warehouse::orderBy('created_at', 'desc')->paginate($perPage);
    }

    // Создание склада с именем и массивом пользователей
    public function createWarehouse($name, array $users)
    {
        $warehouse = new Warehouse();
        $warehouse->name = $name;
        $warehouse->users = $users;

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