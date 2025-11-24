<?php

namespace App\Services;

use App\Models\User;
use App\Models\CashRegister;
use App\Models\Warehouse;

class AccessControlService
{
    /**
     * Проверить доступ пользователя к кассе
     *
     * @param User $user
     * @param int|null $cashRegisterId
     * @return bool
     */
    public function canAccessCashRegister(User $user, ?int $cashRegisterId): bool
    {
        if (!$cashRegisterId) {
            return true;
        }

        $cashRegister = CashRegister::find($cashRegisterId);
        if (!$cashRegister) {
            return false;
        }

        if ($user->hasPermissionTo('cash_registers_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('cash_registers_view_own')) {
            return $cashRegister->hasUser($user->id);
        }

        return $user->hasPermissionTo('cash_registers_view');
    }

    /**
     * Проверить доступ пользователя к складу
     *
     * @param User $user
     * @param int|null $warehouseId
     * @return bool
     */
    public function canAccessWarehouse(User $user, ?int $warehouseId): bool
    {
        if (!$warehouseId) {
            return true;
        }

        $warehouse = Warehouse::find($warehouseId);
        if (!$warehouse) {
            return false;
        }

        if ($user->hasPermissionTo('warehouses_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('warehouses_view_own')) {
            return $warehouse->users()->where('user_id', $user->id)->exists();
        }

        return $user->hasPermissionTo('warehouses_view');
    }
}

