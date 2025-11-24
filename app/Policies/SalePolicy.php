<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Sale;

class SalePolicy
{
    /**
     * Определить, может ли пользователь просматривать список продаж
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('sales_view_all') 
            || $user->hasPermissionTo('sales_view_own')
            || $user->hasPermissionTo('sales_view');
    }

    /**
     * Определить, может ли пользователь просматривать продажу
     *
     * @param User $user
     * @param Sale $sale
     * @return bool
     */
    public function view(User $user, Sale $sale): bool
    {
        if ($user->hasPermissionTo('sales_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('sales_view_own')) {
            return $sale->user_id === $user->id;
        }

        return $user->hasPermissionTo('sales_view');
    }

    /**
     * Определить, может ли пользователь создавать продажи
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('sales_create');
    }

    /**
     * Определить, может ли пользователь удалять продажу
     *
     * @param User $user
     * @param Sale $sale
     * @return bool
     */
    public function delete(User $user, Sale $sale): bool
    {
        if ($user->hasPermissionTo('sales_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('sales_delete_own')) {
            return $sale->user_id === $user->id;
        }

        return $user->hasPermissionTo('sales_delete');
    }
}

