<?php

namespace App\Console\Commands;

use App\Support\ClientBalanceViewAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class GrantClientBalanceTypeViewPermissionsCommand extends Command
{
    protected $signature = 'permissions:grant-client-balance-type-view';

    protected $description = 'Выдаёт ролям с settings_client_balance_view права на просмотр наличного и безналичного баланса';

    /**
     * @return int
     */
    public function handle(): int
    {
        $viewPermission = Permission::query()
            ->where('name', ClientBalanceViewAccess::PERM_VIEW)
            ->where('guard_name', 'api')
            ->first();

        if (! $viewPermission) {
            $this->error('Permission '.ClientBalanceViewAccess::PERM_VIEW.' not found. Run PermissionsSeeder first.');

            return self::FAILURE;
        }

        $typePermissions = [];
        foreach ([ClientBalanceViewAccess::PERM_VIEW_CASH, ClientBalanceViewAccess::PERM_VIEW_NON_CASH] as $name) {
            $typePermissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'api',
            ]);
        }

        $roleIds = DB::table('role_has_permissions')
            ->where('permission_id', $viewPermission->id)
            ->pluck('role_id')
            ->unique();

        $updated = 0;
        foreach ($roleIds as $roleId) {
            $role = Role::query()->where('id', $roleId)->where('guard_name', 'api')->first();
            if (! $role) {
                continue;
            }
            foreach ($typePermissions as $permission) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                    $updated++;
                }
            }
        }

        $this->info("Готово. Добавлено привязок прав к ролям: {$updated}.");

        return self::SUCCESS;
    }
}
