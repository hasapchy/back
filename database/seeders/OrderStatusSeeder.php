<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\OrderStatusCategory;
use App\Models\OrderStatus;

class OrderStatusSeeder extends Seeder
{
    public function run()
    {
        // Create Order Status Categories
        OrderStatusCategory::updateOrCreate(
            ['id' => 1],
            [
                'name' => 'NEW',
                'creator_id' => 1,
                'color' => '#207ac7',
            ]
        );

        OrderStatusCategory::updateOrCreate(
            ['id' => 2],
            [
                'name' => 'IN_PROGRESS',
                'creator_id' => 1,
                'color' => '#5cb85c',
            ]
        );

        OrderStatusCategory::updateOrCreate(
            ['id' => 3],
            [
                'name' => 'READY',
                'creator_id' => 1,
                'color' => '#53585c',
            ]
        );


         OrderStatusCategory::updateOrCreate(
            ['id' => 4],
            [
                'name' => 'COMPLETED',
                'creator_id' => 1,
                'color' => '#939699',
            ]
        );
          OrderStatusCategory::updateOrCreate(
            ['id' => 5],
            [
                'name' => 'CANCELLED',
                'creator_id' => 1,
                'color' => '#d9534f',
            ]
        );

        OrderStatus::updateOrCreate(
            ['id' => 1],
            [
                'name' => 'NEW',
                'category_id' => 1,
            ]
        );

        OrderStatus::updateOrCreate(
            ['id' => 2],
            [
                'name' => 'IN_PROGRESS',
                'category_id' => 2,
            ]
        );

         OrderStatus::updateOrCreate(
            ['id' => 4],
            [
                'name' => 'READY',
                'category_id' => 3,
            ]
        );

        OrderStatus::updateOrCreate(
            ['id' => 5],
            [
                'name' => 'COMPLETED',
                'category_id' => 4,
            ]
        );
         OrderStatus::updateOrCreate(
            ['id' => 6],
            [
                'name' => 'CANCELLED',
                'category_id' => 5,
            ]
        );
    }
}
