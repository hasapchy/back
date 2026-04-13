<?php

namespace Database\Seeders;

use App\Models\OrderStatus;
use App\Models\OrderStatusCategory;
use App\Services\CacheService;
use Illuminate\Database\Seeder;

class OrderStatusSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
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
                'kanban_outcome' => 'success',
            ]
        );
        OrderStatus::updateOrCreate(
            ['id' => 6],
            [
                'name' => 'CANCELLED',
                'category_id' => 5,
                'kanban_outcome' => 'failure',
            ]
        );

        CacheService::invalidateOrderStatusesCache();
    }
}
