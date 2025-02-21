<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UnitsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $units = [
            ['id' => 1, 'name' => 'Метр', 'short_name' => 'м', 'calc_area' => false],
            ['id' => 2, 'name' => 'Квадратный метр', 'short_name' => 'м²', 'calc_area' => true],
            ['id' => 3, 'name' => 'Литр', 'short_name' => 'л', 'calc_area' => false],
            ['id' => 4, 'name' => 'Килограмм', 'short_name' => 'кг', 'calc_area' => false],
            ['id' => 5, 'name' => 'Грамм', 'short_name' => 'г', 'calc_area' => false],
            ['id' => 6, 'name' => 'Штука', 'short_name' => 'шт', 'calc_area' => false],
            ['id' => 7, 'name' => 'Упаковка', 'short_name' => 'уп', 'calc_area' => false],
            ['id' => 8, 'name' => 'Коробка', 'short_name' => 'кор', 'calc_area' => false],
            ['id' => 9, 'name' => 'Паллета', 'short_name' => 'пал', 'calc_area' => false],
            ['id' => 10, 'name' => 'Комплект', 'short_name' => 'комп', 'calc_area' => false],
            ['id' => 12, 'name' => 'Рулон', 'short_name' => 'рул', 'calc_area' => false]
        ];

        foreach ($units as $unit) {
            Unit::updateOrCreate(['id' => $unit['id']], $unit);
        }

    }
}
