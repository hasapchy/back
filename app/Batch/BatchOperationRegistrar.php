<?php

namespace App\Batch;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BatchOperationRegistrar
{
    public static function register(BatchOperationRegistry $registry, Application $app): void
    {
        self::registerProjectsUpdateStatus($registry, $app);
        self::registerOrdersUpdateStatus($registry, $app);
        self::registerClientsDelete($registry, $app);
        self::registerSalesDelete($registry, $app);
        self::registerTasksDelete($registry, $app);
        self::registerUsersDelete($registry, $app);
        self::registerTransactionsDelete($registry, $app);
        self::registerEmployeeSalariesDelete($registry, $app);
    }

    private static function registerProjectsUpdateStatus(BatchOperationRegistry $registry, Application $app): void
    {
        $registry->register(new BatchOperation(
            entity: 'projects',
            action: 'update_status',
            financial: false,
            permissionsAny: [],
            preferredStrategy: 'bulk',
            scopePermissionsAny: ['projects_update_all', 'projects_update'],
            bulkHandler: static function (array $ids, array $payload, $user, ?int $companyId) use ($app) {
                $validator = Validator::make($payload, [
                    'status_id' => 'required|integer|exists:project_statuses,id',
                ]);
                if ($validator->fails()) {
                    throw new ValidationException($validator);
                }
                try {
                    $affected = $app->make(BatchEntityActions::class)->updateProjectStatuses(
                        $user,
                        $ids,
                        (int) $payload['status_id'],
                    );
                } catch (\InvalidArgumentException $e) {
                    return new BatchResult(
                        successCount: 0,
                        failedIds: $ids,
                        errors: [['message' => $e->getMessage()]],
                    );
                } catch (\Throwable $e) {
                    return new BatchResult(
                        successCount: 0,
                        failedIds: $ids,
                        errors: [['message' => $e->getMessage() ?: 'Ошибка смены статуса']],
                    );
                }

                return new BatchResult(
                    successCount: $affected,
                    failedIds: [],
                    errors: [],
                );
            },
        ));
    }

    private static function registerOrdersUpdateStatus(BatchOperationRegistry $registry, Application $app): void
    {
        $registry->register(new BatchOperation(
            entity: 'orders',
            action: 'update_status',
            financial: false,
            permissionsAny: [],
            preferredStrategy: 'bulk',
            scopePermissionsAny: [
                'orders_update_all',
                'orders_update',
                'orders_simple_update_all',
                'orders_simple_update',
            ],
            bulkHandler: static function (array $ids, array $payload, $user, ?int $companyId) use ($app) {
                $validator = Validator::make($payload, [
                    'status_id' => 'required|integer|exists:order_statuses,id',
                ]);
                if ($validator->fails()) {
                    throw new ValidationException($validator);
                }
                try {
                    $result = $app->make(BatchEntityActions::class)->updateOrderStatuses(
                        $user,
                        $ids,
                        (int) $payload['status_id'],
                        $companyId,
                    );
                } catch (\InvalidArgumentException $e) {
                    return new BatchResult(
                        successCount: 0,
                        failedIds: $ids,
                        errors: [['message' => $e->getMessage()]],
                    );
                } catch (\Throwable $e) {
                    return new BatchResult(
                        successCount: 0,
                        failedIds: $ids,
                        errors: [['message' => $e->getMessage() ?: 'Ошибка смены статуса']],
                    );
                }
                if (is_array($result) && ! empty($result['needs_payment'])) {
                    return new BatchResult(
                        successCount: 0,
                        failedIds: $ids,
                        errors: [[
                            'code' => 'orders_needs_payment',
                            'message' => $result['message'] ?? 'Требуется оплата',
                            'order_id' => $result['order_id'] ?? null,
                            'remaining_amount' => $result['remaining_amount'] ?? null,
                            'paid_total' => $result['paid_total'] ?? null,
                            'order_total' => $result['order_total'] ?? null,
                        ]],
                    );
                }

                return new BatchResult(
                    successCount: (int) $result,
                    failedIds: [],
                    errors: [],
                );
            },
        ));
    }

    private static function registerClientsDelete(BatchOperationRegistry $registry, Application $app): void
    {
        $registry->register(new BatchOperation(
            entity: 'clients',
            action: 'delete',
            financial: false,
            permissionsAny: [],
            preferredStrategy: 'loop',
            scopePermissionsAny: ['clients_delete_all', 'clients_delete'],
            loopHandler: static function (int $id, array $payload, $user, ?int $companyId) use ($app) {
                $app->make(BatchEntityActions::class)->deleteClient($user, $id);
            },
        ));
    }

    private static function registerSalesDelete(BatchOperationRegistry $registry, Application $app): void
    {
        $registry->register(new BatchOperation(
            entity: 'sales',
            action: 'delete',
            financial: false,
            permissionsAny: [],
            preferredStrategy: 'loop',
            scopePermissionsAny: ['sales_delete_all', 'sales_delete'],
            loopHandler: static function (int $id, array $payload, $user, ?int $companyId) use ($app) {
                $app->make(BatchEntityActions::class)->deleteSale($user, $id);
            },
        ));
    }

    private static function registerTasksDelete(BatchOperationRegistry $registry, Application $app): void
    {
        $registry->register(new BatchOperation(
            entity: 'tasks',
            action: 'delete',
            financial: false,
            permissionsAny: [],
            preferredStrategy: 'loop',
            scopePermissionsAny: ['tasks_delete_all', 'tasks_delete'],
            loopHandler: static function (int $id, array $payload, $user, ?int $companyId) use ($app) {
                $app->make(BatchEntityActions::class)->deleteTask($id);
            },
        ));
    }

    private static function registerUsersDelete(BatchOperationRegistry $registry, Application $app): void
    {
        $registry->register(new BatchOperation(
            entity: 'users',
            action: 'delete',
            financial: false,
            permissionsAny: [],
            preferredStrategy: 'loop',
            scopePermissionsAny: ['users_delete_all', 'users_delete'],
            loopHandler: static function (int $id, array $payload, $user, ?int $companyId) use ($app) {
                $app->make(BatchEntityActions::class)->deleteUser($user, $id);
            },
        ));
    }

    private static function registerTransactionsDelete(BatchOperationRegistry $registry, Application $app): void
    {
        $registry->register(new BatchOperation(
            entity: 'transactions',
            action: 'delete',
            financial: true,
            permissionsAny: [],
            preferredStrategy: 'loop',
            scopePermissionsAny: ['transactions_delete_all', 'transactions_delete'],
            loopHandler: static function (int $id, array $payload, $user, ?int $companyId) use ($app) {
                $app->make(BatchEntityActions::class)->deleteTransaction($user, $id);
            },
        ));
    }

    private static function registerEmployeeSalariesDelete(BatchOperationRegistry $registry, Application $app): void
    {
        $registry->register(new BatchOperation(
            entity: 'employee_salaries',
            action: 'delete',
            financial: false,
            permissionsAny: [],
            preferredStrategy: 'loop',
            loopHandler: static function (int $id, array $payload, $user, ?int $companyId) use ($app) {
                $app->make(BatchEntityActions::class)->deleteEmployeeSalary($user, $id);
            },
            allowPartialFailure: false,
            scopePermissionsAny: ['employee_salaries_delete_all', 'employee_salaries_delete_own'],
        ));
    }
}
