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
        DB::table('product_statuses')->updateOrInsert(
            ['id' => 1],
            ['name' => 'в наличии']
        );

        DB::table('product_statuses')->updateOrInsert(
            ['id' => 2],
            ['name' => 'продано']
        );

        DB::table('product_statuses')->updateOrInsert(
            ['id' => 3],
            ['name' => 'списан']
        );
    }
}
