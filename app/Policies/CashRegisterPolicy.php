<?php

namespace App\Policies;

use App\Models\User;
use App\Models\CashRegister;

class CashRegisterPolicy
{
    /**
     * Определить, может ли пользователь просматривать список касс
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('cash_registers_view_all') 
            || $user->hasPermissionTo('cash_registers_view_own')
            || $user->hasPermissionTo('cash_registers_view');
    }

    /**
     * Определить, может ли пользователь просматривать кассу
     *
     * @param User $user
     * @param CashRegister $cashRegister
     * @return bool
     */
    public function view(User $user, CashRegister $cashRegister): bool
    {
        if ($user->hasPermissionTo('cash_registers_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('cash_registers_view_own')) {
            return $cashRegister->hasUser($user->id);
        }

        return $user->hasPermissionTo('cash_registers_view');
    }

    /**
     * Определить, может ли пользователь создавать кассы
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('cash_registers_create');
    }

    /**
     * Определить, может ли пользователь обновлять кассу
     *
     * @param User $user
     * @param CashRegister $cashRegister
     * @return bool
     */
    public function update(User $user, CashRegister $cashRegister): bool
    {
        if ($user->hasPermissionTo('cash_registers_update_all')) {
            return true;
        }

        if ($user->hasPermissionTo('cash_registers_update_own')) {
            return $cashRegister->hasUser($user->id);
        }

        return $user->hasPermissionTo('cash_registers_update');
    }

    /**
     * Определить, может ли пользователь удалять кассу
     *
     * @param User $user
     * @param CashRegister $cashRegister
     * @return bool
     */
    public function delete(User $user, CashRegister $cashRegister): bool
    {
        if ($user->hasPermissionTo('cash_registers_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('cash_registers_delete_own')) {
            return $cashRegister->hasUser($user->id);
        }

        return $user->hasPermissionTo('cash_registers_delete');
    }

    /**
     * Определить, может ли пользователь просматривать баланс кассы
     *
     * @param User $user
     * @param CashRegister $cashRegister
     * @return bool
     */
    public function viewBalance(User $user, CashRegister $cashRegister): bool
    {
        return $user->hasPermissionTo('settings_cash_balance_view');
    }
}

