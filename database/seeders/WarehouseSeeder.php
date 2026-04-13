<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use App\Models\WhUser;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $warehouse = Warehouse::find(1);
        if (!$warehouse) {
            $warehouse = Warehouse::query()->create([
                'id' => 1,
                'name' => 'Основной склад',
                'company_id' => 1,
            ]);
        }

        WhUser::firstOrCreate([
            'warehouse_id' => $warehouse->id,
            'user_id' => 1,
        ]);
    }
}
