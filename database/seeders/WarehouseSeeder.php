<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Warehouse;
use App\Models\WhUser;

class WarehouseSeeder extends Seeder
{
    public function run()
    {
        $warehouse = Warehouse::updateOrCreate([
            'id' => 1
        ], [
            'name' => 'Основной склад',
        ]);

        // Добавляем пользователя с ID 1 в основной склад
        WhUser::updateOrCreate([
            'warehouse_id' => $warehouse->id,
            'user_id' => 1
        ], [
            'warehouse_id' => $warehouse->id,
            'user_id' => 1
        ]);
    }
}
