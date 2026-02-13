<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Миграция данных из старой БД (old_hasapp) в hasap-central и tenant-БД.
 * Использует stancl/tenancy для инициализации tenant-контекста.
 */
class MigrateOldDataToTenants extends Command
{
    protected $signature = 'migrate:old-to-tenants
                            {--dry-run : Только вывод плана, без записи}
                            {--company-id= : Миграция только указанной компании}';

    protected $description = 'Перенос данных из old_hasapp в hasap-central и tenant-БД';

    private const CHUNK_SIZE = 200;

    /** Центральные таблицы (в hasap-central) */
    private const CENTRAL_TABLES = ['users', 'permissions', 'role_has_permissions', 'roles', 'company_user', 'company_user_role', 'companies'];

    /** Tenant-таблицы с company_id, порядок по зависимостям FK */
    private const TENANT_TABLES_WITH_COMPANY = [
        'currencies',
        'currency_histories',
        'warehouses',
        'order_status_categories',
        'order_statuses',
        'project_statuses',
        'task_statuses',
        'leave_types',
        'product_statuses',
        'categories',
        'projects',
        'departments',
        'clients',
        'clients_emails',
        'clients_phones',
        'category_users',
        'project_users',
        'department_user',
        'cash_registers',
        'cash_register_users',
        'transaction_categories',
        'transactions',
        'cash_transfers',
        'client_balances',
        'client_balance_users',
        'orders',
        'order_products',
        'order_af',
        'order_af_values',
        'order_temp_products',
        'products',
        'product_prices',
        'product_categories',
        'project_contracts',
        'tasks',
        'comments',
        'employee_salaries',
        'salary_accruals',
        'salary_accrual_items',
        'leaves',
        'company_holidays',
        'company_rounding_rules',
        'message_templates',
        'news',
        'chats',
        'chat_messages',
        'chat_participants',
        'message_reactions',
        'activity_log',
        'wh_receipts',
        'wh_receipts_products',
        'wh_write_offs',
        'wh_write_off_products',
        'wh_movements',
        'wh_movement_products',
        'warehouse_stocks',
        'sales',
        'sales_products',
        'invoices',
        'invoice_orders',
        'invoice_products',
    ];

    /** Таблицы без company_id — мигрировать все записи в каждый tenant */
    private const TENANT_TABLES_NO_COMPANY = ['order_status_categories', 'order_statuses', 'product_statuses', 'leave_types', 'project_statuses', 'task_statuses', 'units'];

    /** Таблицы без company_id — фильтр через FK: [fk_table, fk_column] или [fk_table, fk_column, filter_col, filter_table] если fk_table без company_id */
    private const TENANT_TABLES_VIA_FK = [
        'currency_histories' => ['currencies', 'currency_id'],
        'cash_register_users' => ['cash_registers', 'cash_register_id'],
        'transactions' => ['cash_registers', 'cash_id'],
        'orders' => ['clients', 'client_id'],
        'chat_messages' => ['chats', 'chat_id'],
        'chat_participants' => ['chats', 'chat_id'],
        'invoices' => ['clients', 'client_id'],
        'invoice_orders' => ['invoices', 'invoice_id', 'client_id', 'clients'],
        'invoice_products' => ['invoices', 'invoice_id', 'client_id', 'clients'],
        'product_categories' => ['categories', 'category_id'],
        'products' => ['product_categories', 'category_id', 'categories', 'product_id', 'id'],
        'product_prices' => ['product_categories', 'category_id', 'categories', 'product_id', 'product_id'],
        'project_contracts' => ['projects', 'project_id'],
        'wh_write_off_products' => ['wh_write_offs', 'write_off_id', 'warehouse_id', 'warehouses'],
        'order_products' => ['orders', 'order_id', 'client_id', 'clients'],
        'warehouse_stocks' => ['warehouses', 'warehouse_id'],
        'sales' => ['clients', 'client_id'],
        'sales_products' => ['sales', 'sale_id', 'client_id', 'clients'],
        'salary_accrual_items' => ['transactions', 'transaction_id', 'cash_id', 'cash_registers'],
        'clients_phones' => ['clients', 'client_id'],
        'clients_emails' => ['clients', 'client_id'],
        'category_users' => ['categories', 'category_id'],
        'cash_transfers' => ['cash_registers', 'cash_id_from'],
        'client_balances' => ['clients', 'client_id'],
        'department_user' => ['departments', 'department_id'],
        'client_balance_users' => ['client_balances', 'client_balance_id', 'client_id', 'clients'],
        'order_temp_products' => ['orders', 'order_id', 'client_id', 'clients'],
        'message_reactions' => ['chat_messages', 'message_id', 'chat_id', 'chats'],
        'wh_receipts' => ['clients', 'supplier_id'],
        'wh_write_offs' => ['warehouses', 'warehouse_id'],
        'wh_movements' => ['warehouses', 'wh_from'],
        'wh_movement_products' => ['wh_movements', 'movement_id', 'wh_from', 'warehouses'],
    ];

    /** Имена таблиц в старой БД, если отличаются (старая => текущая) */
    private const OLD_TABLE_NAMES = [
        'product_categories' => 'product_category',
        'comments' => 'comment',
    ];

    /** Значения по умолчанию для null при вставке (старая БД может иметь null, целевая — NOT NULL) */
    private const DEFAULT_NULL_VALUES = [
        'order_products' => ['discount' => 0],
    ];

    private bool $dryRun = false;
    private ?int $companyIdFilter = null;
    private array $companyToTenantMap = [];
    /** Маппинг old permission_id -> central permission_id */
    private array $permissionIdMap = [];
    /** Маппинг old role_id -> central role_id */
    private array $roleIdMap = [];
    /** Маппинг old user_id -> central user_id */
    private array $userIdMap = [];

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $companyIdOpt = $this->option('company-id');
        $this->companyIdFilter = $companyIdOpt !== null && $companyIdOpt !== '' ? (int) $companyIdOpt : null;

        if ($this->dryRun) {
            $this->warn('Режим dry-run: данные не будут записаны.');
        }

        if (!$this->checkOldConnection()) {
            return 1;
        }

        $centralConn = config('tenancy.database.central_connection', 'mysql');

        try {
            $this->info('Фаза 1: Центральные данные...');
            $this->migrateCentral($centralConn);

            $this->info('Фаза 2: Tenant-данные...');
            $this->migrateTenants($centralConn);
        } catch (\Throwable $e) {
            $this->error('Ошибка: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }

        $this->info('Миграция завершена.');
        return 0;
    }

    /** Проверка соединения с old_mysql */
    private function checkOldConnection(): bool
    {
        try {
            DB::connection('old_mysql')->getPdo();
            DB::connection('old_mysql')->getDatabaseName();
            $this->info('Соединение с old_mysql OK.');
            return true;
        } catch (\Throwable $e) {
            $this->error('Не удалось подключиться к old_mysql: ' . $e->getMessage());
            $this->line('Убедитесь, что OLD_DB_DATABASE настроен и hasapNewBackup.sql импортирован.');
            return false;
        }
    }

    /** Центральные таблицы: users, permissions, roles, role_has_permissions */
    private function migrateCentralTables($old, $central): void
    {
        $this->migrateUsers($old, $central);
        $this->migratePermissions($old, $central);
        $this->migrateRoles($old, $central);
        $this->migrateRoleHasPermissions($old, $central);
    }

    /** Центральные данные с company: companies, company_user, company_user_role */
    private function migrateCentralCompanyData($old, $central, $companies): void
    {
        $this->migrateCompanies($old, $central, $companies);
        $this->migrateCompanyUser($old, $central);
        $this->migrateCompanyUserRole($old, $central);
        $this->migrateModelHasRoles($old, $central);
    }

    /** Миграция центральных данных */
    private function migrateCentral(string $centralConn): void
    {
        $old = DB::connection('old_mysql');
        $central = DB::connection($centralConn);

        $companies = $old->table('companies')->when($this->companyIdFilter, fn ($q) => $q->where('id', $this->companyIdFilter))->get();
        if ($companies->isEmpty()) {
            $this->warn('Компании не найдены в old DB.');
            return;
        }

        if (!$this->dryRun) {
            $central->transaction(fn () => $this->migrateCentralTables($old, $central));

            foreach ($companies as $company) {
                $tenant = $this->getOrCreateTenantForCompany($central, $company);
                $this->companyToTenantMap[$company->id] = $tenant;
            }

            $central->transaction(fn () => $this->migrateCentralCompanyData($old, $central, $companies));
        } else {
            $this->line('  [dry-run] users, permissions, roles, companies, company_user, company_user_role');
            foreach ($companies as $c) {
                $this->line("  [dry-run] Tenant для компании #{$c->id} ({$c->name})");
            }
        }
    }

    private function migrateUsers($old, $central): void
    {
        $existingIds = $central->table('users')->pluck('id')->flip()->toArray();
        $existingEmails = $central->table('users')->get()->keyBy('email');
        $count = 0;
        $old->table('users')->orderBy('id')->chunk(self::CHUNK_SIZE, function ($users) use ($central, &$existingIds, &$existingEmails, &$count) {
            foreach ($users as $user) {
                if (isset($existingIds[$user->id])) {
                    $this->userIdMap[$user->id] = $user->id;
                    continue;
                }
                $centralUser = $existingEmails->get($user->email);
                if ($centralUser) {
                    $this->userIdMap[$user->id] = $centralUser->id;
                    continue;
                }
                try {
                    $central->table('users')->insert($this->filterColumns('users', (array) $user, $central));
                    $this->userIdMap[$user->id] = $user->id;
                    $existingIds[$user->id] = true;
                    $existingEmails[$user->email] = (object) ['id' => $user->id, 'email' => $user->email];
                    $count++;
                } catch (\Throwable $e) {
                    if (str_contains($e->getMessage(), 'Duplicate entry')) {
                        $fresh = $central->table('users')->where('email', $user->email)->first();
                        if ($fresh) {
                            $this->userIdMap[$user->id] = $fresh->id;
                            $existingEmails[$user->email] = (object) ['id' => $fresh->id, 'email' => $user->email];
                        } else {
                            throw $e;
                        }
                    } else {
                        throw $e;
                    }
                }
            }
        });
        $this->info("  users: {$count} записей");
    }

    private function migratePermissions($old, $central): void
    {
        $existingByKey = $central->table('permissions')->get()->keyBy(fn ($r) => "{$r->name}_{$r->guard_name}");
        $count = 0;
        foreach ($old->table('permissions')->get() as $row) {
            $key = "{$row->name}_{$row->guard_name}";
            $centralPerm = $existingByKey->get($key);
            if ($centralPerm) {
                $this->permissionIdMap[$row->id] = $centralPerm->id;
                continue;
            }
            try {
                $central->table('permissions')->insert($this->filterColumns('permissions', (array) $row, $central));
                $this->permissionIdMap[$row->id] = $row->id;
                $existingByKey[$key] = (object) ['id' => $row->id, 'name' => $row->name, 'guard_name' => $row->guard_name];
                $count++;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    $fresh = $central->table('permissions')->where('name', $row->name)->where('guard_name', $row->guard_name)->first();
                    $this->permissionIdMap[$row->id] = $fresh?->id ?? $row->id;
                } else {
                    throw $e;
                }
            }
        }
        $this->info("  permissions: {$count} записей");
    }

    private function migrateRoles($old, $central): void
    {
        $query = $old->table('roles')->when($this->companyIdFilter, fn ($q) => $q->where('company_id', $this->companyIdFilter));
        $existingByKey = $central->table('roles')->get()->keyBy(fn ($r) => ($r->company_id ?? '') . "_{$r->name}_{$r->guard_name}");
        $count = 0;
        foreach ($query->get() as $row) {
            $key = ($row->company_id ?? '') . "_{$row->name}_{$row->guard_name}";
            $centralRole = $existingByKey->get($key);
            if ($centralRole) {
                $this->roleIdMap[$row->id] = $centralRole->id;
                continue;
            }
            try {
                $central->table('roles')->insert($this->filterColumns('roles', (array) $row, $central));
                $this->roleIdMap[$row->id] = $row->id;
                $existingByKey[$key] = (object) ['id' => $row->id, 'name' => $row->name, 'guard_name' => $row->guard_name, 'company_id' => $row->company_id ?? null];
                $count++;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    $q = $central->table('roles')->where('name', $row->name)->where('guard_name', $row->guard_name);
                    $q = isset($row->company_id) ? $q->where('company_id', $row->company_id) : $q->whereNull('company_id');
                    $fresh = $q->first();
                    $this->roleIdMap[$row->id] = $fresh?->id ?? $row->id;
                } else {
                    throw $e;
                }
            }
        }
        $this->info("  roles: {$count} записей");
    }

    private function migrateRoleHasPermissions($old, $central): void
    {
        $existing = $central->table('role_has_permissions')->get()->keyBy(fn ($r) => "{$r->role_id}_{$r->permission_id}");
        $count = 0;
        foreach ($old->table('role_has_permissions')->get() as $row) {
            $centralRoleId = $this->roleIdMap[$row->role_id] ?? $row->role_id;
            $centralPermId = $this->permissionIdMap[$row->permission_id] ?? $row->permission_id;
            $key = "{$centralRoleId}_{$centralPermId}";
            if ($existing->has($key)) {
                continue;
            }
            $data = $this->filterColumns('role_has_permissions', (array) $row, $central);
            $data['role_id'] = $centralRoleId;
            $data['permission_id'] = $centralPermId;
            $central->table('role_has_permissions')->insertOrIgnore($data);
            $existing[$key] = true;
            $count++;
        }
        $this->info("  role_has_permissions: {$count} записей");
    }

    /**
     * Возвращает существующий tenant компании из central или создаёт новый.
     * При повторном запуске не создаёт дубликаты tenant.
     */
    private function getOrCreateTenantForCompany($central, $company): Tenant
    {
        $existing = $central->table('companies')->where('id', $company->id)->first();
        if ($existing && !empty($existing->tenant_id)) {
            $tenant = Tenant::on('central')->where('id', $existing->tenant_id)->first();
            if ($tenant) {
                $this->line("  Tenant для компании #{$company->id} ({$company->name}): {$tenant->id} (существующий)");
                return $tenant;
            }
        }
        $tenant = Tenant::on('central')->create();
        $this->line("  Tenant создан для компании #{$company->id} ({$company->name}): {$tenant->id}");
        return $tenant;
    }

    private function migrateCompanies($old, $central, $companies): void
    {
        $count = 0;
        foreach ($companies as $company) {
            $tenant = $this->companyToTenantMap[$company->id] ?? null;
            if (!$tenant) {
                continue;
            }
            $data = (array) $company;
            $data['tenant_id'] = $tenant->id;
            if (($data['logo'] ?? '') === 'logo.jpg') {
                $data['logo'] = 'logo.png';
            }
            $data['work_schedule'] = $data['work_schedule'] ?? null;
            $central->table('companies')->updateOrInsert(['id' => $company->id], $this->filterColumns('companies', $data, $central));
            $count++;
        }
        $this->info("  companies: {$count} записей");
    }

    private function migrateCompanyUser($old, $central): void
    {
        $query = $old->table('company_user')->when($this->companyIdFilter, fn ($q) => $q->where('company_id', $this->companyIdFilter));
        $count = 0;
        foreach ($query->get() as $row) {
            $centralUserId = $this->userIdMap[$row->user_id] ?? null;
            if ($centralUserId === null) {
                continue;
            }
            $data = $this->filterColumns('company_user', (array) $row, $central);
            $data['user_id'] = $centralUserId;
            $central->table('company_user')->updateOrInsert(
                ['company_id' => $row->company_id, 'user_id' => $centralUserId],
                $data
            );
            $count++;
        }
        $this->info("  company_user: {$count} записей");
    }

    private function migrateCompanyUserRole($old, $central): void
    {
        $query = $old->table('company_user_role')->when($this->companyIdFilter, fn ($q) => $q->where('company_id', $this->companyIdFilter));
        $count = 0;
        foreach ($query->get() as $row) {
            $centralUserId = $this->userIdMap[$row->user_id] ?? null;
            $centralRoleId = $this->roleIdMap[$row->role_id] ?? $row->role_id;
            if ($centralUserId === null) {
                continue;
            }
            $data = $this->filterColumns('company_user_role', (array) $row, $central);
            $data['user_id'] = $centralUserId;
            $data['role_id'] = $centralRoleId;
            $central->table('company_user_role')->updateOrInsert(
                ['company_id' => $row->company_id, 'user_id' => $centralUserId, 'role_id' => $centralRoleId],
                $data
            );
            $count++;
        }
        $this->info("  company_user_role: {$count} записей");
    }

    private function migrateModelHasRoles($old, $central): void
    {
        $existing = $central->table('company_user_role')->get()->keyBy(fn ($r) => "{$r->company_id}_{$r->user_id}_{$r->role_id}");
        $oldRoles = $old->table('roles')->get()->keyBy('id');
        $rows = $old->table('model_has_roles')
            ->where('model_type', 'like', '%User')
            ->get();
        $count = 0;
        foreach ($rows as $row) {
            $centralUserId = $this->userIdMap[$row->model_id] ?? null;
            $centralRoleId = $this->roleIdMap[$row->role_id] ?? $row->role_id;
            if ($centralUserId === null) {
                continue;
            }
            $role = $oldRoles->get($row->role_id);
            if (!$role || !$role->company_id) {
                continue;
            }
            if ($this->companyIdFilter && (int) $role->company_id !== $this->companyIdFilter) {
                continue;
            }
            $key = "{$role->company_id}_{$centralUserId}_{$centralRoleId}";
            if ($existing->has($key)) {
                continue;
            }
            $central->table('company_user_role')->insertOrIgnore([
                'company_id' => $role->company_id,
                'user_id' => $centralUserId,
                'role_id' => $centralRoleId,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
            $count++;
        }
        $this->info("  model_has_roles -> company_user_role: {$count} записей");
    }

    /** Миграция tenant-данных */
    private function migrateTenants(string $centralConn): void
    {
        $old = DB::connection('old_mysql');
        $companies = $old->table('companies')->when($this->companyIdFilter, fn ($q) => $q->where('id', $this->companyIdFilter))->get();

        foreach ($companies as $company) {
            $tenant = $this->companyToTenantMap[$company->id] ?? Tenant::on('central')->where('id', DB::connection($centralConn)->table('companies')->where('id', $company->id)->value('tenant_id'))->first();
            if (!$tenant) {
                $this->warn("  Tenant не найден для компании #{$company->id}, пропуск.");
                continue;
            }

            $this->info("  Миграция tenant для компании #{$company->id} ({$company->name})...");

            if ($this->dryRun) {
                $this->line("    [dry-run] tenant-таблицы для company_id={$company->id}");
                continue;
            }

            tenancy()->initialize($tenant);

            try {
                DB::transaction(function () use ($old, $company) {
                    $this->migrateTenantTables($old, $company->id);
                });
            } finally {
                tenancy()->end();
            }
        }
    }

    private function migrateTenantTables($old, int $companyId): void
    {
        $tables = array_unique(array_merge(
            self::TENANT_TABLES_NO_COMPANY,
            self::TENANT_TABLES_WITH_COMPANY
        ));

        foreach ($tables as $table) {
            $oldTableName = $this->getOldTableName($table);
            if (!$old->getSchemaBuilder()->hasTable($table) && !$old->getSchemaBuilder()->hasTable($oldTableName)) {
                continue;
            }
            if (!Schema::hasTable($table)) {
                continue;
            }
            $sourceTable = $old->getSchemaBuilder()->hasTable($table) ? $table : $oldTableName;

            if ($table === 'comments') {
                $this->copyTableComments($old, $companyId, $sourceTable);
                continue;
            }
            if (isset(self::TENANT_TABLES_VIA_FK[$table])) {
                $cfg = self::TENANT_TABLES_VIA_FK[$table];
                if (count($cfg) >= 5) {
                    $this->copyTableViaJunctionIds($old, $table, $sourceTable, $cfg[0], $cfg[1], $cfg[2], $cfg[3], $cfg[4], $companyId);
                } else {
                    $this->copyTableViaFk($old, $table, $sourceTable, $cfg[0], $cfg[1], $companyId, $cfg[2] ?? null, $cfg[3] ?? null);
                }
            } else {
                $hasCompanyId = $old->getSchemaBuilder()->hasColumn($sourceTable, 'company_id');
                if ($hasCompanyId) {
                    $this->chunkCopy($old, $table, $companyId, $sourceTable);
                } else {
                    $this->chunkCopyAll($old, $table, $sourceTable);
                }
            }
        }
    }

    /** Имя таблицы в старой БД (если отличается от текущего) */
    private function getOldTableName(string $table): string
    {
        return self::OLD_TABLE_NAMES[$table] ?? $table;
    }

    private function chunkCopy($old, string $table, int $companyId, string $sourceTable = null): void
    {
        $sourceTable = $sourceTable ?? $table;
        $targetCols = Schema::getColumnListing($table);
        $count = 0;
        $old->table($sourceTable)->where('company_id', $companyId)->orderBy('id')->chunk(self::CHUNK_SIZE, function ($rows) use ($table, $targetCols, &$count) {
            foreach ($rows as $row) {
                $data = $this->applyDefaultsForInsert($table, $this->filterColumnsByList((array) $row, $targetCols));
                try {
                    DB::table($table)->insertOrIgnore($data);
                    $count++;
                } catch (\Throwable $e) {
                    $this->warn("    {$table}: " . $e->getMessage());
                }
            }
        });
        if ($count > 0) {
            $this->line("    {$table}: {$count}");
        }
    }

    /** Копирование таблиц без company_id (все записи) */
    private function chunkCopyAll($old, string $table, string $sourceTable = null): void
    {
        $sourceTable = $sourceTable ?? $table;
        $targetCols = Schema::getColumnListing($table);
        $existingIds = DB::table($table)->pluck('id')->flip()->toArray();
        $count = 0;
        $old->table($sourceTable)->orderBy('id')->chunk(self::CHUNK_SIZE, function ($rows) use ($table, $targetCols, &$existingIds, &$count) {
            foreach ($rows as $row) {
                if (isset($existingIds[$row->id])) {
                    continue;
                }
                $data = $this->applyDefaultsForInsert($table, $this->filterColumnsByList((array) $row, $targetCols));
                try {
                    DB::table($table)->insert($data);
                    $existingIds[$row->id] = true;
                    $count++;
                } catch (\Throwable $e) {
                    $this->warn("    {$table}: " . $e->getMessage());
                }
            }
        });
        if ($count > 0) {
            $this->line("    {$table}: {$count}");
        }
    }

    /** Копирование через junction: ids = junction where filterFk IN (filterTable where company_id) -> pluck(pluckCol); copy table where targetCol IN (ids) */
    private function copyTableViaJunctionIds($old, string $table, string $sourceTable, string $junction, string $filterFk, string $filterTable, string $pluckCol, string $targetCol, int $companyId): void
    {
        $oldJunction = $this->getOldTableName($junction);
        $hasJunction = $old->getSchemaBuilder()->hasTable($junction) || $old->getSchemaBuilder()->hasTable($oldJunction);
        $junctionTable = $old->getSchemaBuilder()->hasTable($junction) ? $junction : $oldJunction;
        $ids = $hasJunction
            ? $old->table($junctionTable)->whereIn($filterFk, $old->table($filterTable)->where('company_id', $companyId)->pluck('id'))->pluck($pluckCol)->unique()->toArray()
            : [];
        // Fallback: в старой БД products может иметь category_id — добавить такие id
        if ($table === 'products' && $old->getSchemaBuilder()->hasColumn('products', 'category_id')) {
            $idsFromCategory = $old->table('products')->whereIn('category_id', $old->table('categories')->where('company_id', $companyId)->pluck('id'))->pluck('id')->toArray();
            $ids = array_values(array_unique(array_merge($ids, $idsFromCategory)));
        }
        if (empty($ids)) {
            return;
        }
        $targetCols = Schema::getColumnListing($table);
        $count = 0;
        $old->table($sourceTable)->whereIn($targetCol, $ids)->orderBy('id')->chunk(self::CHUNK_SIZE, function ($rows) use ($table, $targetCols, &$count) {
            foreach ($rows as $row) {
                $data = $this->applyDefaultsForInsert($table, $this->filterColumnsByList((array) $row, $targetCols));
                if ($table === 'products' && isset($data['unit_id']) && $data['unit_id'] && !DB::table('units')->where('id', $data['unit_id'])->exists()) {
                    $data['unit_id'] = null;
                }
                try {
                    DB::table($table)->insertOrIgnore($data);
                    $count++;
                } catch (\Throwable $e) {
                    $this->warn("    {$table}: " . $e->getMessage());
                }
            }
        });
        if ($count > 0) {
            $this->line("    {$table}: {$count}");
        }
    }

    /** Копирование таблиц, связанных через FK. Если fkTable без company_id — filterCol/filterTable: ids = fkTable where filterCol IN (filterTable where company_id=X) */
    private function copyTableViaFk($old, string $table, string $sourceTable, string $fkTable, string $fkColumn, int $companyId, ?string $filterCol = null, ?string $filterTable = null): void
    {
        $ids = $filterCol && $filterTable
            ? $old->table($fkTable)->whereIn($filterCol, $old->table($filterTable)->where('company_id', $companyId)->pluck('id'))->pluck('id')->toArray()
            : $old->table($fkTable)->where('company_id', $companyId)->pluck('id')->toArray();
        if (empty($ids)) {
            return;
        }
        $targetCols = Schema::getColumnListing($table);
        $count = 0;
        $old->table($sourceTable)->whereIn($fkColumn, $ids)->orderBy('id')->chunk(self::CHUNK_SIZE, function ($rows) use ($table, $targetCols, &$count) {
            foreach ($rows as $row) {
                $data = $this->applyDefaultsForInsert($table, $this->filterColumnsByList((array) $row, $targetCols));
                if ($table === 'chat_participants' && !empty($data['last_read_message_id']) && !DB::table('chat_messages')->where('id', $data['last_read_message_id'])->exists()) {
                    $data['last_read_message_id'] = null;
                }
                if ($table === 'cash_transfers' && !empty($data['cash_id_to']) && !DB::table('cash_registers')->where('id', $data['cash_id_to'])->exists()) {
                    continue;
                }
                if ($table === 'salary_accrual_items' && !empty($data['transaction_id']) && !DB::table('transactions')->where('id', $data['transaction_id'])->exists()) {
                    continue;
                }
                if ($table === 'wh_movements' && !empty($data['wh_to']) && !DB::table('warehouses')->where('id', $data['wh_to'])->exists()) {
                    continue;
                }
                try {
                    DB::table($table)->insertOrIgnore($data);
                    $count++;
                } catch (\Throwable $e) {
                    $this->warn("    {$table}: " . $e->getMessage());
                }
            }
        });
        if ($count > 0) {
            $this->line("    {$table}: {$count}");
        }
    }

    /**
     * Копирование comments по полиморфной связи: только комментарии к Project, Task, Order, Transaction компании.
     */
    private function copyTableComments($old, int $companyId, string $sourceTable): void
    {
        $projectIds = $old->table('projects')->where('company_id', $companyId)->pluck('id')->toArray();
        $taskIds = !empty($projectIds)
            ? $old->table('tasks')->whereIn('project_id', $projectIds)->pluck('id')->toArray()
            : [];
        $clientIds = $old->table('clients')->where('company_id', $companyId)->pluck('id')->toArray();
        $orderIds = !empty($clientIds)
            ? $old->table('orders')->whereIn('client_id', $clientIds)->pluck('id')->toArray()
            : [];
        $cashIds = $old->table('cash_registers')->where('company_id', $companyId)->pluck('id')->toArray();
        $transactionIds = !empty($cashIds)
            ? $old->table('transactions')->whereIn('cash_id', $cashIds)->pluck('id')->toArray()
            : [];

        if (empty($projectIds) && empty($taskIds) && empty($orderIds) && empty($transactionIds)) {
            return;
        }

        // Строим условия только для непустых списков id, первый блок — where(), остальные — orWhere()
        $query = $old->table($sourceTable)->where(function ($q) use ($projectIds, $taskIds, $orderIds, $transactionIds) {
            $first = true;
            if (!empty($projectIds)) {
                $q->where(function ($q2) use ($projectIds) {
                    $q2->where('commentable_type', 'like', '%Project')->whereIn('commentable_id', $projectIds);
                }, null, null, $first ? 'and' : 'or');
                $first = false;
            }
            if (!empty($taskIds)) {
                $q->where(function ($q2) use ($taskIds) {
                    $q2->where('commentable_type', 'like', '%Task')->whereIn('commentable_id', $taskIds);
                }, null, null, $first ? 'and' : 'or');
                $first = false;
            }
            if (!empty($orderIds)) {
                $q->where(function ($q2) use ($orderIds) {
                    $q2->where('commentable_type', 'like', '%Order')->whereIn('commentable_id', $orderIds);
                }, null, null, $first ? 'and' : 'or');
                $first = false;
            }
            if (!empty($transactionIds)) {
                $q->where(function ($q2) use ($transactionIds) {
                    $q2->where('commentable_type', 'like', '%Transaction')->whereIn('commentable_id', $transactionIds);
                }, null, null, $first ? 'and' : 'or');
            }
        });

        $targetCols = Schema::getColumnListing('comments');
        $count = 0;
        $query->orderBy('id')->chunk(self::CHUNK_SIZE, function ($rows) use ($targetCols, &$count) {
            foreach ($rows as $row) {
                $data = $this->applyDefaultsForInsert('comments', $this->filterColumnsByList((array) $row, $targetCols));
                try {
                    DB::table('comments')->insertOrIgnore($data);
                    $count++;
                } catch (\Throwable $e) {
                    $this->warn("    comments: " . $e->getMessage());
                }
            }
        });
        if ($count > 0) {
            $this->line("    comments: {$count}");
        }
    }

    private function filterColumns(string $table, array $data, $connection): array
    {
        $cols = $connection->getSchemaBuilder()->getColumnListing($table);
        return $this->filterColumnsByList($data, $cols);
    }

    private function filterColumnsByList(array $data, array $allowedCols): array
    {
        $allowed = array_flip($allowedCols);
        $result = [];
        foreach ($data as $k => $v) {
            if (isset($allowed[$k])) {
                $result[$k] = $v;
            }
        }
        return $result;
    }

    /** Применяет defaults для null/отсутствующих полей перед вставкой */
    private function applyDefaultsForInsert(string $table, array $data): array
    {
        $defaults = self::DEFAULT_NULL_VALUES[$table] ?? [];
        foreach ($defaults as $col => $default) {
            if (!array_key_exists($col, $data) || $data[$col] === null) {
                $data[$col] = $default;
            }
        }
        return $data;
    }
}
