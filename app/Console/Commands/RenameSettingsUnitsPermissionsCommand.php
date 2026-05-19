<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class RenameSettingsUnitsPermissionsCommand extends Command
{
    protected $signature = 'permissions:rename-settings-units {--revert : Вернуть settings_units_manage вместо create/edit}';

    protected $description = 'Переименовывает settings_units_manage в settings_units_edit и добавляет settings_units_create';

    /**
     * @return int
     */
    public function handle(): int
    {
        if ($this->option('revert')) {
            return $this->revert();
        }

        return $this->apply();
    }

    /**
     * @return int
     */
    private function apply(): int
    {
        $renamed = DB::table('permissions')
            ->where('name', 'settings_units_manage')
            ->where('guard_name', 'api')
            ->update(['name' => 'settings_units_edit']);

        if ($renamed > 0) {
            $this->info('Переименовано: settings_units_manage → settings_units_edit');
        } elseif (Permission::query()->where('name', 'settings_units_edit')->where('guard_name', 'api')->exists()) {
            $this->line('settings_units_edit уже существует, переименование пропущено.');
        } else {
            $this->warn('Не найдено право settings_units_manage для переименования.');

            return self::FAILURE;
        }

        $createPermission = Permission::firstOrCreate([
            'name' => 'settings_units_create',
            'guard_name' => 'api',
        ]);
        $this->info('Право settings_units_create создано или уже существует.');

        $editPermission = Permission::query()
            ->where('name', 'settings_units_edit')
            ->where('guard_name', 'api')
            ->first();

        if ($editPermission === null) {
            $this->error('Право settings_units_edit не найдено после переименования.');

            return self::FAILURE;
        }

        $roleIds = DB::table('role_has_permissions')
            ->where('permission_id', $editPermission->id)
            ->pluck('role_id');

        $granted = 0;
        foreach ($roleIds as $roleId) {
            $inserted = DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $createPermission->id,
                'role_id' => $roleId,
            ]);
            if ($inserted) {
                $granted++;
            }
        }

        $this->info("Право settings_units_create выдано ролям: {$granted}.");

        return self::SUCCESS;
    }

    /**
     * @return int
     */
    private function revert(): int
    {
        $createPermission = Permission::query()
            ->where('name', 'settings_units_create')
            ->where('guard_name', 'api')
            ->first();

        if ($createPermission !== null) {
            DB::table('role_has_permissions')->where('permission_id', $createPermission->id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $createPermission->id)->delete();
            $createPermission->delete();
            $this->info('Удалено право settings_units_create.');
        }

        $reverted = DB::table('permissions')
            ->where('name', 'settings_units_edit')
            ->where('guard_name', 'api')
            ->update(['name' => 'settings_units_manage']);

        if ($reverted > 0) {
            $this->info('Переименовано: settings_units_edit → settings_units_manage');
        } else {
            $this->warn('Право settings_units_edit не найдено для отката.');
        }

        return self::SUCCESS;
    }
}
