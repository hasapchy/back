<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SyncBasementWorkerOrderPermissionsCommand extends Command
{
    protected $signature = 'roles:sync-basement-worker-order-permissions {--revert : Вернуть orders_* вместо orders_simple_*}';

    protected $description = 'Синхронизирует права роли basement_worker: orders_simple_* вместо legacy orders_*';

    /**
     * @return int
     */
    public function handle(): int
    {
        $revert = (bool) $this->option('revert');

        if ($revert) {
            $remove = ['orders_simple_view_own', 'orders_simple_create', 'orders_simple_update_own'];
            $grant = ['orders_view', 'orders_create', 'orders_update'];
        } else {
            $remove = ['orders_view', 'orders_create', 'orders_update'];
            $grant = ['orders_simple_view_own', 'orders_simple_create', 'orders_simple_update_own'];
        }

        $roles = Role::query()
            ->where('name', 'basement_worker')
            ->where('guard_name', 'api')
            ->get();

        if ($roles->isEmpty()) {
            $this->warn('Роли basement_worker (guard api) не найдены.');

            return self::SUCCESS;
        }

        foreach ($roles as $role) {
            foreach ($remove as $name) {
                if (Permission::query()->where('name', $name)->where('guard_name', 'api')->exists()) {
                    $role->revokePermissionTo($name);
                }
            }
            foreach ($grant as $name) {
                if (Permission::query()->where('name', $name)->where('guard_name', 'api')->exists()) {
                    $role->givePermissionTo($name);
                } else {
                    $this->warn("Право {$name} отсутствует в БД, пропуск.");
                }
            }
            $this->info("Обновлена роль basement_worker id={$role->id} company_id=".($role->company_id ?? 'null'));
        }

        return self::SUCCESS;
    }
}
