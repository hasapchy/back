<?php

namespace App\Services\Timeline;

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Sale;
use App\Models\Task;
use App\Models\Transaction;
use InvalidArgumentException;

class TimelineModelRegistry
{
    /**
     * @return array{select: list<string>, with: list<string>, merge_order_transaction_logs: bool}
     */
    public static function config(string $modelClass): array
    {
        return match ($modelClass) {
            Task::class => [
                'select' => ['creator_id', 'supervisor_id', 'executor_id', 'status_id', 'project_id'],
                'with' => [
                    'creator:id,name',
                    'supervisor:id,name',
                    'executor:id,name',
                    'status:id,name',
                    'project:id,name',
                ],
                'merge_order_transaction_logs' => false,
            ],
            Order::class => [
                'select' => ['client_id', 'creator_id', 'status_id', 'category_id'],
                'with' => [
                    'client:id,first_name,last_name',
                    'creator:id,name',
                    'status:id,name',
                    'category:id,name',
                ],
                'merge_order_transaction_logs' => true,
            ],
            Transaction::class => [
                'select' => ['client_id', 'creator_id', 'category_id'],
                'with' => [
                    'client:id,first_name,last_name',
                    'creator:id,name',
                    'category:id,name',
                ],
                'merge_order_transaction_logs' => false,
            ],
            Sale::class => [
                'select' => ['client_id', 'creator_id'],
                'with' => [
                    'client:id,first_name,last_name',
                    'creator:id,name',
                ],
                'merge_order_transaction_logs' => false,
            ],
            Project::class => [
                'select' => ['client_id', 'creator_id', 'status_id'],
                'with' => [
                    'client:id,first_name,last_name',
                    'creator:id,name',
                    'status:id,name',
                ],
                'merge_order_transaction_logs' => false,
            ],
            ProjectContract::class => [
                'select' => ['project_id', 'creator_id', 'currency_id', 'cash_id'],
                'with' => [
                    'project:id,name',
                    'creator:id,name',
                    'currency:id,symbol,name',
                    'cashRegister:id,name',
                ],
                'merge_order_transaction_logs' => false,
            ],
            Client::class => [
                'select' => ['creator_id'],
                'with' => [
                    'creator:id,name',
                ],
                'merge_order_transaction_logs' => false,
            ],
            Product::class => [
                'select' => ['creator_id'],
                'with' => [
                    'creator:id,name',
                ],
                'merge_order_transaction_logs' => false,
            ],
            default => throw new InvalidArgumentException('Timeline не поддерживается для данной модели'),
        };
    }
}
