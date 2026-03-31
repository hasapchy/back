<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\Warehouse;
use App\Models\WhUser;

class WarehouseSeeder extends Seeder
{
    /**
     * Создает основной склад и привязывает его к компании.
     */
    public function run()
    {
        // Берем первую существующую компанию без создания новой записи.
        $company = Company::query()->first();

        if (!$company) {
            return;
        }

        $warehouse = Warehouse::updateOrCreate([
            'id' => 1
        ], [
            'name' => 'Основной склад',
            'company_id' => $company->id,
        ]);

        // Добавляем пользователя с ID 1 в основной склад
        WhUser::updateOrCreate([
            'warehouse_id' => $warehouse->id,
            'creator_id' => 1
        ], [
            'warehouse_id' => $warehouse->id,
            'creator_id' => 1
        ]);
    }
}
