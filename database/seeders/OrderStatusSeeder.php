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
                'name' => 'Новые',
                'user_id' => 1,
                'color' => '#207ac7',
            ]
        );

        OrderStatusCategory::updateOrCreate(
            ['id' => 2],
            [
                'name' => 'В работе',
                'user_id' => 1,
                'color' => '#5cb85c',
            ]
        );

        OrderStatusCategory::updateOrCreate(
            ['id' => 3],
            [
                'name' => 'Готово',
                'user_id' => 1,
                'color' => '#53585c',
            ]
        );


         OrderStatusCategory::updateOrCreate(
            ['id' => 4],
            [
                'name' => 'Завершено',
                'user_id' => 1,
                'color' => '#939699',
            ]
        );
          OrderStatusCategory::updateOrCreate(
            ['id' => 5],
            [
                'name' => 'Отменено',
                'user_id' => 1,
                'color' => '#d9534f',
            ]
        );

        OrderStatus::updateOrCreate(
            ['id' => 1],
            [
                'name' => 'Новый',
                'category_id' => 1,
            ]
        );

        OrderStatus::updateOrCreate(
            ['id' => 2],
            [
                'name' => 'В работе',
                'category_id' => 2,
            ]
        );

         OrderStatus::updateOrCreate(
            ['id' => 4],
            [
                'name' => 'Готово',
                'category_id' => 3,
            ]
        );

        OrderStatus::updateOrCreate(
            ['id' => 5],
            [
                'name' => 'Завершено',
                'category_id' => 4,
            ]
        );
         OrderStatus::updateOrCreate(
            ['id' => 6],
            [
                'name' => 'Отменено',
                'category_id' => 5,
            ]
        );
    }
}
