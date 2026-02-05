<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class UnitsSeeder extends Seeder
{
    /**
     * Сидер для tenant-БД. Пропускает выполнение в центральном контексте.
     */
    public function run(): void
    {
        if (!Schema::hasTable('units')) {
            return;
        }

        $units = [
            ['id' => 1, 'name' => 'METER', 'short_name' => 'м'],
            ['id' => 2, 'name' => 'SQUARE_METER', 'short_name' => 'м²'],
            ['id' => 3, 'name' => 'LITER', 'short_name' => 'л'],
            ['id' => 4, 'name' => 'KILOGRAM', 'short_name' => 'кг'],
            ['id' => 5, 'name' => 'GRAM', 'short_name' => 'г'],
            ['id' => 6, 'name' => 'PIECE', 'short_name' => 'шт'],
            ['id' => 7, 'name' => 'PACKAGE', 'short_name' => 'уп'],
            ['id' => 8, 'name' => 'BOX', 'short_name' => 'кор'],
            ['id' => 9, 'name' => 'PALLET', 'short_name' => 'пал'],
            ['id' => 10, 'name' => 'SET', 'short_name' => 'комп'],
            ['id' => 12, 'name' => 'ROLL', 'short_name' => 'рул']
        ];

        foreach ($units as $unit) {
            Unit::updateOrCreate(['id' => $unit['id']], $unit);
        }

    }
}
