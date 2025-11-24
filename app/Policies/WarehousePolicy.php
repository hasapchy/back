<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;

class WarehousePolicy
{
    /**
     * Определить, может ли пользователь просматривать список складов
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('warehouses_view_all') 
            || $user->hasPermissionTo('warehouses_view_own')
            || $user->hasPermissionTo('warehouses_view');
    }

    /**
     * Определить, может ли пользователь просматривать склад
     *
     * @param User $user
     * @param Warehouse $warehouse
     * @return bool
     */
    public function view(User $user, Warehouse $warehouse): bool
    {
        if ($user->hasPermissionTo('warehouses_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('warehouses_view_own')) {
            return $warehouse->users()->where('user_id', $user->id)->exists();
        }

        return $user->hasPermissionTo('warehouses_view');
    }

    /**
     * Определить, может ли пользователь создавать склады
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('warehouses_create');
    }

    /**
     * Определить, может ли пользователь обновлять склад
     *
     * @param User $user
     * @param Warehouse $warehouse
     * @return bool
     */
    public function update(User $user, Warehouse $warehouse): bool
    {
        if ($user->hasPermissionTo('warehouses_update_all')) {
            return true;
        }

        if ($user->hasPermissionTo('warehouses_update_own')) {
            return $warehouse->users()->where('user_id', $user->id)->exists();
        }

        return $user->hasPermissionTo('warehouses_update');
    }

    /**
     * Определить, может ли пользователь удалять склад
     *
     * @param User $user
     * @param Warehouse $warehouse
     * @return bool
     */
    public function delete(User $user, Warehouse $warehouse): bool
    {
        if ($user->hasPermissionTo('warehouses_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('warehouses_delete_own')) {
            return $warehouse->users()->where('user_id', $user->id)->exists();
        }

        return $user->hasPermissionTo('warehouses_delete');
    }
}

