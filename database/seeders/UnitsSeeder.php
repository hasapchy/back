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
            ['id' => 1, 'name' => 'Метр', 'short_name' => 'м'],
            ['id' => 2, 'name' => 'Квадратный метр', 'short_name' => 'м²'],
            ['id' => 3, 'name' => 'Литр', 'short_name' => 'л'],
            ['id' => 4, 'name' => 'Килограмм', 'short_name' => 'кг'],
            ['id' => 5, 'name' => 'Грамм', 'short_name' => 'г'],
            ['id' => 6, 'name' => 'Штука', 'short_name' => 'шт'],
            ['id' => 7, 'name' => 'Упаковка', 'short_name' => 'уп'],
            ['id' => 8, 'name' => 'Коробка', 'short_name' => 'кор'],
            ['id' => 9, 'name' => 'Паллета', 'short_name' => 'пал'],
            ['id' => 10, 'name' => 'Комплект', 'short_name' => 'комп'],
            ['id' => 12, 'name' => 'Рулон', 'short_name' => 'рул']
        ];

        foreach ($units as $unit) {
            Unit::updateOrCreate(['id' => $unit['id']], $unit);
        }

    }
}
