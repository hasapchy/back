<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('product_statuses')->insert([
            ['id' => 1, 'name' => 'в наличии'],
            ['id' => 2, 'name' => 'продано'],
            ['id' => 3, 'name' => 'списан'],
        ]);
    }
}
