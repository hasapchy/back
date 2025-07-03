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
                'color' => '#FF0000',
            ]
        );

        OrderStatusCategory::updateOrCreate(
            ['id' => 2],
            [
                'name' => 'В работе',
                'user_id' => 1,
                'color' => '#00FF00',
            ]
        );

        OrderStatusCategory::updateOrCreate(
            ['id' => 3],
            [
                'name' => 'Готово',
                'user_id' => 1,
                'color' => '#0000FF',
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
            ['id' => 3],
            [
                'name' => 'Оплачено',
                'category_id' => 2,
            ]
        );

        OrderStatus::updateOrCreate(
            ['id' => 4],
            [
                'name' => 'Завершено',
                'category_id' => 3,
            ]
        );
    }
}
