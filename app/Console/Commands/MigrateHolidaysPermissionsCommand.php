<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class MigrateHolidaysPermissionsCommand extends Command
{
    protected $signature = 'permissions:migrate-holidays';

    protected $description = 'Переносит holidays_* и production_calendar_* в holidays_* и production_calendar_*';

    /**
     * @var array<string, string>
     */
    private array $renameMap = [
        'holidays_view' => 'holidays_view',
        'holidays_view_all' => 'holidays_view_all',
        'holidays_view_own' => 'holidays_view_own',
        'holidays_create' => 'holidays_create',
        'holidays_update' => 'holidays_update',
        'holidays_update_all' => 'holidays_update_all',
        'holidays_update_own' => 'holidays_update_own',
        'holidays_delete' => 'holidays_delete',
        'holidays_delete_all' => 'holidays_delete_all',
        'holidays_delete_own' => 'holidays_delete_own',
        'holidays_export' => 'holidays_export',
        'production_calendar_view' => 'production_calendar_view',
        'production_calendar_view_all' => 'production_calendar_view_all',
        'production_calendar_view_own' => 'production_calendar_view_own',
        'production_calendar_create' => 'production_calendar_create',
        'production_calendar_update' => 'production_calendar_update',
        'production_calendar_update_all' => 'production_calendar_update_all',
        'production_calendar_update_own' => 'production_calendar_update_own',
        'production_calendar_delete' => 'production_calendar_delete',
        'production_calendar_delete_all' => 'production_calendar_delete_all',
        'production_calendar_delete_own' => 'production_calendar_delete_own',
        'production_calendar_export' => 'production_calendar_export',
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

        if ($migrated === 0) {
            $this->line('Legacy-прав holidays_* / production_calendar_* не найдено.');
        }

        $this->info("Миграция прав завершена (обновлено: {$migrated}).");

        return self::SUCCESS;
    }

    /**
     * @return bool
     */
    private function migratePermission(string $oldName, string $newName): bool
    {
        $old = Permission::query()
            ->where('name', $oldName)
            ->where('guard_name', 'api')
            ->first();

        if (! $old) {
            return false;
        }

        $target = Permission::query()->firstOrCreate([
            'name' => $newName,
            'guard_name' => 'api',
        ]);

        $roleIds = DB::table('role_has_permissions')
            ->where('permission_id', $old->id)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $target->id,
                'role_id' => $roleId,
            ]);
        }

        $directBindings = DB::table('model_has_permissions')
            ->where('permission_id', $old->id)
            ->get();

        foreach ($directBindings as $binding) {
            DB::table('model_has_permissions')->insertOrIgnore([
                'permission_id' => $target->id,
                'model_type' => $binding->model_type,
                'model_id' => $binding->model_id,
            ]);
        }

        DB::table('role_has_permissions')->where('permission_id', $old->id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $old->id)->delete();
        $old->delete();

        $this->info("{$oldName} -> {$newName}");

        return true;
    }
}
