<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Order;

class OrderPolicy
{
    /**
     * Определить, может ли пользователь просматривать список заказов
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('orders_view_all') 
            || $user->hasPermissionTo('orders_view_own')
            || $user->hasPermissionTo('orders_view');
    }

    /**
     * Определить, может ли пользователь просматривать заказ
     *
     * @param User $user
     * @param Order $order
     * @return bool
     */
    public function view(User $user, Order $order): bool
    {
        if ($user->hasPermissionTo('orders_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('orders_view_own')) {
            return $order->user_id === $user->id;
        }

        return $user->hasPermissionTo('orders_view');
    }

    /**
     * Определить, может ли пользователь создавать заказы
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('orders_create');
    }

    /**
     * Определить, может ли пользователь обновлять заказ
     *
     * @param User $user
     * @param Order $order
     * @return bool
     */
    public function update(User $user, Order $order): bool
    {
        if ($user->hasPermissionTo('orders_update_all')) {
            return true;
        }

        if ($user->hasPermissionTo('orders_update_own')) {
            return $order->user_id === $user->id;
        }

        return $user->hasPermissionTo('orders_update');
    }

    /**
     * Определить, может ли пользователь удалять заказ
     *
     * @param User $user
     * @param Order $order
     * @return bool
     */
    public function delete(User $user, Order $order): bool
    {
        if ($user->hasPermissionTo('orders_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('orders_delete_own')) {
            return $order->user_id === $user->id;
        }

        return $user->hasPermissionTo('orders_delete');
    }
}

