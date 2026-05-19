<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class MigrateUnitsPermissionsCommand extends Command
{
    protected $signature = 'permissions:migrate-units';

    protected $description = 'Переносит legacy settings_units_* в units_* (безопасно запускать повторно)';

    /**
     * @var array<string, string>
     */
    private array $renameMap = [
        'settings_units_view' => 'units_view',
        'settings_units_create' => 'units_create',
        'settings_units_edit' => 'units_update',
        'settings_units_manage' => 'units_update',
    ];

    /**
     * @return int
     */
    public function handle(): int
    {
        $migrated = 0;

        foreach ($this->renameMap as $oldName => $newName) {
            if ($this->migratePermission($oldName, $newName)) {
                $migrated++;
            }
        }

        $created = $this->ensureTargetPermissionsExist();

        $deleteGranted = $this->grantDeleteToRolesWithUpdate();

        if ($migrated === 0 && $created === 0 && $deleteGranted === 0) {
            $this->line('Legacy-прав settings_units_* не найдено — перенос не требуется.');
        }

        $this->info('Миграция прав units завершена.');

        return self::SUCCESS;
    }

    /**
     * @return int
     */
    private function ensureTargetPermissionsExist(): int
    {
        $created = 0;

        foreach (['units_view', 'units_create', 'units_update', 'units_delete'] as $name) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'api',
            ]);

            if ($permission->wasRecentlyCreated) {
                $created++;
                $this->info("Создано право {$name}");
            }
        }

        return $created;
    }

    /**
     * @return int
     */
    private function grantDeleteToRolesWithUpdate(): int
    {
        $update = Permission::query()
            ->where('name', 'units_update')
            ->where('guard_name', 'api')
            ->first();

        $delete = Permission::query()
            ->where('name', 'units_delete')
            ->where('guard_name', 'api')
            ->first();

        if ($update === null || $delete === null) {
            return 0;
        }

        $roleIds = DB::table('role_has_permissions')
            ->where('permission_id', $update->id)
            ->pluck('role_id');

        $granted = 0;

        foreach ($roleIds as $roleId) {
            $inserted = DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $delete->id,
                'role_id' => $roleId,
            ]);

            if ($inserted) {
                $granted++;
            }
        }

        if ($granted > 0) {
            $this->info("units_delete выдано ролям: {$granted}");
        }

        return $granted;
    }

    /**
     * @return bool true если legacy-право было обработано
     */
    private function migratePermission(string $oldName, string $newName): bool
    {
        $old = Permission::query()
            ->where('name', $oldName)
            ->where('guard_name', 'api')
            ->first();

        if ($old === null) {
            return false;
        }

        $target = Permission::query()
            ->where('name', $newName)
            ->where('guard_name', 'api')
            ->first();

        if ($target === null) {
            $old->update(['name' => $newName]);
            $this->info("{$oldName} → {$newName}");

            return true;
        }

        $this->reassignPermissionBindings($old->id, $target->id);
        $old->delete();
        $this->info("{$oldName} объединено с {$newName}");

        return true;
    }

    /**
     * @return void
     */
    private function reassignPermissionBindings(int $fromId, int $toId): void
    {
        $roleIds = DB::table('role_has_permissions')
            ->where('permission_id', $fromId)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $toId,
                'role_id' => $roleId,
            ]);
        }

        DB::table('role_has_permissions')->where('permission_id', $fromId)->delete();

        $directBindings = DB::table('model_has_permissions')
            ->where('permission_id', $fromId)
            ->get();

        foreach ($directBindings as $binding) {
            DB::table('model_has_permissions')->insertOrIgnore([
                'permission_id' => $toId,
                'model_type' => $binding->model_type,
                'model_id' => $binding->model_id,
            ]);
        }

        DB::table('model_has_permissions')->where('permission_id', $fromId)->delete();
    }
}
