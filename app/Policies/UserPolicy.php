<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Определить, может ли пользователь просматривать список пользователей
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('users_view_all') 
            || $user->hasPermissionTo('users_view_own')
            || $user->hasPermissionTo('users_view');
    }

    /**
     * Определить, может ли пользователь просматривать другого пользователя
     *
     * @param User $user
     * @param User $targetUser
     * @return bool
     */
    public function view(User $user, User $targetUser): bool
    {
        if ($user->hasPermissionTo('users_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('users_view_own')) {
            return $targetUser->id === $user->id;
        }

        return $user->hasPermissionTo('users_view');
    }

    /**
     * Определить, может ли пользователь создавать пользователей
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('users_create');
    }

    /**
     * Определить, может ли пользователь обновлять другого пользователя
     *
     * @param User $user
     * @param User $targetUser
     * @return bool
     */
    public function update(User $user, User $targetUser): bool
    {
        if ($user->hasPermissionTo('users_update_all')) {
            return true;
        }

        if ($user->hasPermissionTo('users_update_own')) {
            return $targetUser->id === $user->id;
        }

        return $user->hasPermissionTo('users_update');
    }

    /**
     * Определить, может ли пользователь удалять другого пользователя
     *
     * @param User $user
     * @param User $targetUser
     * @return bool
     */
    public function delete(User $user, User $targetUser): bool
    {
        if ($user->hasPermissionTo('users_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('users_delete_own')) {
            return $targetUser->id === $user->id;
        }

        return $user->hasPermissionTo('users_delete');
    }

    /**
     * Определить, может ли пользователь просматривать баланс другого пользователя
     *
     * @param User $user
     * @param User $targetUser
     * @return bool
     */
    public function viewBalance(User $user, User $targetUser): bool
    {
        return $user->hasPermissionTo('settings_client_balance_view');
    }
}

