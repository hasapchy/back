<?php

namespace App\Services\Timeline;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Sale;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\WhMovement;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;
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
            Lead::class => [
                'select' => ['client_id', 'creator_id', 'responsible_id', 'status_id', 'lead_source_id'],
                'with' => [
                    'client:id,first_name,last_name',
                    'creator:id,name',
                    'responsible:id,name',
                    'status:id,name',
                    'source:id,name',
                ],
                'merge_order_transaction_logs' => false,
            ],
            Product::class => [
                'select' => ['creator_id', 'unit_id', 'name', 'sku', 'type'],
                'with' => [
                    'creator:id,name',
                    'unit:id,name',
                ],
                'merge_order_transaction_logs' => false,
            ],
            WhReceipt::class => [
                'select' => ['supplier_id', 'purchase_id', 'warehouse_id', 'creator_id', 'cash_id', 'client_balance_id', 'status'],
                'with' => [
                    'supplier:id,first_name,last_name',
                    'warehouse:id,name',
                    'creator:id,name',
                    'cashRegister:id,name',
                    'purchase:id',
                    'clientBalance:id,client_id',
                ],
                'merge_order_transaction_logs' => false,
            ],
            WhWriteoff::class => [
                'select' => ['warehouse_id', 'creator_id', 'source_receipt_id', 'reason'],
                'with' => [
                    'warehouse:id,name',
                    'creator:id,name',
                    'sourceReceipt:id',
                ],
                'merge_order_transaction_logs' => false,
            ],
            WhMovement::class => [
                'select' => ['wh_from', 'wh_to', 'creator_id', 'date', 'note'],
                'with' => [
                    'warehouseFrom:id,name',
                    'warehouseTo:id,name',
                    'creator:id,name',
                ],
                'merge_order_transaction_logs' => false,
            ],
            WhPurchase::class => [
                'select' => ['supplier_id', 'warehouse_id', 'creator_id', 'cash_id', 'currency_id', 'client_balance_id', 'status', 'amount', 'date', 'note'],
                'with' => [
                    'supplier:id,first_name,last_name',
                    'warehouse:id,name',
                    'creator:id,name',
                    'cashRegister:id,name',
                    'currency:id,symbol,name',
                    'clientBalance:id,client_id',
                ],
                'merge_order_transaction_logs' => false,
            ],
            default => throw new InvalidArgumentException('Timeline не поддерживается для данной модели'),
        };
    }
}
