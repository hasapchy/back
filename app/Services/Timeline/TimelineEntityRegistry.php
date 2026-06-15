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

final class TimelineEntityRegistry
{
    public const LINES_HEADER_ONLY = 'header_only';

    public const LINES_SUMMARY = 'summary';

    /**
     * @return list<string>
     */
    public static function skipLogNames(): array
    {
        return ['order_product', 'order_temp_product', 'inventory_item'];
    }

    /**
     * @return list<string>
     */
    public static function skipDescriptionSuffixes(): array
    {
        return ['.products_updated'];
    }

    /**
     * @return array<string, array{
     *     api_type: string,
     *     model: class-string,
     *     log_name: string,
     *     lines: string,
     *     merge_transaction_logs: bool,
     *     company_resolver: string,
     *     select: list<string>,
     *     with: list<string>
     * }>
     */
    private static function definitions(): array
    {
        return [
            'task' => [
                'api_type' => 'task',
                'model' => Task::class,
                'log_name' => 'task',
                'lines' => self::LINES_HEADER_ONLY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'company_id',
                'select' => ['creator_id', 'supervisor_id', 'executor_id', 'status_id', 'project_id'],
                'with' => [
                    'creator:id,name',
                    'supervisor:id,name',
                    'executor:id,name',
                    'status:id,name',
                    'project:id,name',
                ],
            ],
            'order' => [
                'api_type' => 'order',
                'model' => Order::class,
                'log_name' => 'order',
                'lines' => self::LINES_SUMMARY,
                'merge_transaction_logs' => true,
                'company_resolver' => 'order',
                'select' => ['client_id', 'creator_id', 'status_id', 'category_id'],
                'with' => [
                    'client:id,first_name,last_name',
                    'creator:id,name',
                    'status:id,name',
                    'category:id,name',
                ],
            ],
            'transaction' => [
                'api_type' => 'transaction',
                'model' => Transaction::class,
                'log_name' => 'transaction',
                'lines' => self::LINES_HEADER_ONLY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'company_id',
                'select' => ['client_id', 'creator_id', 'category_id'],
                'with' => [
                    'client:id,first_name,last_name',
                    'creator:id,name',
                    'category:id,name',
                ],
            ],
            'sale' => [
                'api_type' => 'sale',
                'model' => Sale::class,
                'log_name' => 'sale',
                'lines' => self::LINES_SUMMARY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'sale',
                'select' => ['client_id', 'creator_id'],
                'with' => [
                    'client:id,first_name,last_name',
                    'creator:id,name',
                ],
            ],
            'project' => [
                'api_type' => 'project',
                'model' => Project::class,
                'log_name' => 'project',
                'lines' => self::LINES_HEADER_ONLY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'company_id',
                'select' => ['client_id', 'creator_id', 'status_id'],
                'with' => [
                    'client:id,first_name,last_name',
                    'creator:id,name',
                    'status:id,name',
                ],
            ],
            'project_contract' => [
                'api_type' => 'project_contract',
                'model' => ProjectContract::class,
                'log_name' => 'project_contract',
                'lines' => self::LINES_HEADER_ONLY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'project_contract',
                'select' => ['project_id', 'creator_id', 'currency_id', 'cash_id'],
                'with' => [
                    'project:id,name',
                    'creator:id,name',
                    'currency:id,code,name',
                    'cashRegister:id,name',
                ],
            ],
            'client' => [
                'api_type' => 'client',
                'model' => Client::class,
                'log_name' => 'client',
                'lines' => self::LINES_HEADER_ONLY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'company_id',
                'select' => ['creator_id'],
                'with' => [
                    'creator:id,name',
                ],
            ],
            'lead' => [
                'api_type' => 'lead',
                'model' => Lead::class,
                'log_name' => 'lead',
                'lines' => self::LINES_HEADER_ONLY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'company_id',
                'select' => ['client_id', 'creator_id', 'responsible_id', 'status_id', 'lead_source_id'],
                'with' => [
                    'client:id,first_name,last_name',
                    'creator:id,name',
                    'responsible:id,name',
                    'status:id,name',
                    'source:id,name',
                ],
            ],
            'product' => [
                'api_type' => 'product',
                'model' => Product::class,
                'log_name' => 'product',
                'lines' => self::LINES_HEADER_ONLY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'product',
                'select' => ['creator_id', 'unit_id', 'name', 'sku', 'type'],
                'with' => [
                    'creator:id,name',
                    'unit:id,name',
                ],
            ],
            'wh_receipt' => [
                'api_type' => 'wh_receipt',
                'model' => WhReceipt::class,
                'log_name' => 'wh_receipt',
                'lines' => self::LINES_SUMMARY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'warehouse',
                'select' => ['supplier_id', 'purchase_id', 'warehouse_id', 'creator_id', 'cash_id', 'client_balance_id', 'status'],
                'with' => [
                    'supplier:id,first_name,last_name',
                    'warehouse:id,name',
                    'creator:id,name',
                    'cashRegister:id,name',
                    'purchase:id',
                    'clientBalance:id,client_id',
                ],
            ],
            'wh_writeoff' => [
                'api_type' => 'wh_writeoff',
                'model' => WhWriteoff::class,
                'log_name' => 'wh_writeoff',
                'lines' => self::LINES_SUMMARY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'warehouse',
                'select' => ['warehouse_id', 'creator_id', 'source_receipt_id', 'reason'],
                'with' => [
                    'warehouse:id,name',
                    'creator:id,name',
                    'sourceReceipt:id',
                ],
            ],
            'wh_movement' => [
                'api_type' => 'wh_movement',
                'model' => WhMovement::class,
                'log_name' => 'wh_movement',
                'lines' => self::LINES_SUMMARY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'warehouse_from',
                'select' => ['wh_from', 'wh_to', 'creator_id', 'date', 'note'],
                'with' => [
                    'warehouseFrom:id,name',
                    'warehouseTo:id,name',
                    'creator:id,name',
                ],
            ],
            'wh_purchase' => [
                'api_type' => 'wh_purchase',
                'model' => WhPurchase::class,
                'log_name' => 'wh_purchase',
                'lines' => self::LINES_SUMMARY,
                'merge_transaction_logs' => false,
                'company_resolver' => 'supplier',
                'select' => ['supplier_id', 'warehouse_id', 'creator_id', 'cash_id', 'currency_id', 'client_balance_id', 'status', 'amount', 'date', 'note'],
                'with' => [
                    'supplier:id,first_name,last_name',
                    'warehouse:id,name',
                    'creator:id,name',
                    'cashRegister:id,name',
                    'currency:id,code,name',
                    'clientBalance:id,client_id',
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     api_type: string,
     *     model: class-string,
     *     log_name: string,
     *     lines: string,
     *     merge_transaction_logs: bool,
     *     company_resolver: string,
     *     select: list<string>,
     *     with: list<string>
     * }
     */
    public static function forModelClass(string $modelClass): array
    {
        foreach (self::definitions() as $definition) {
            if ($definition['model'] === $modelClass) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Timeline не поддерживается для данной модели: {$modelClass}");
    }

    /**
     * @return array{
     *     api_type: string,
     *     model: class-string,
     *     log_name: string,
     *     lines: string,
     *     merge_transaction_logs: bool,
     *     company_resolver: string,
     *     select: list<string>,
     *     with: list<string>
     * }
     */
    public static function forApiType(string $apiType): array
    {
        $definition = self::definitions()[$apiType] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException("Unknown timeline api type: {$apiType}");
        }

        return $definition;
    }

    /**
     * @return class-string
     */
    public static function modelClassFromApiType(string $apiType): string
    {
        return self::forApiType($apiType)['model'];
    }

    /**
     * @param  class-string  $modelClass
     */
    public static function apiTypeFromModelClass(string $modelClass): string
    {
        return self::forModelClass($modelClass)['api_type'];
    }

    /**
     * @return array{select: list<string>, with: list<string>, merge_order_transaction_logs: bool}
     */
    public static function presenterConfig(string $modelClass): array
    {
        $definition = self::forModelClass($modelClass);

        return [
            'select' => $definition['select'],
            'with' => $definition['with'],
            'merge_order_transaction_logs' => $definition['merge_transaction_logs'],
        ];
    }

    /**
     * @param  string|null  $logName
     * @param  string|null  $description
     */
    public static function shouldSkipBroadcast(?string $logName, ?string $description): bool
    {
        if ($logName !== null && in_array($logName, self::skipLogNames(), true)) {
            return true;
        }

        if (! is_string($description)) {
            return false;
        }

        foreach (self::skipDescriptionSuffixes() as $suffix) {
            if (str_ends_with($description, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
