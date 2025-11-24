<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Client;

class ClientPolicy
{
    /**
     * Определить, может ли пользователь просматривать список клиентов
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('clients_view_all') 
            || $user->hasPermissionTo('clients_view_own')
            || $user->hasPermissionTo('clients_view');
    }

    /**
     * Определить, может ли пользователь просматривать клиента
     *
     * @param User $user
     * @param Client $client
     * @return bool
     */
    public function view(User $user, Client $client): bool
    {
        if ($user->hasPermissionTo('clients_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('clients_view_own')) {
            return $client->user_id === $user->id;
        }

        return $user->hasPermissionTo('clients_view');
    }

    /**
     * Определить, может ли пользователь создавать клиентов
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('clients_create');
    }

    /**
     * Определить, может ли пользователь обновлять клиента
     *
     * @param User $user
     * @param Client $client
     * @return bool
     */
    public function update(User $user, Client $client): bool
    {
        if ($user->hasPermissionTo('clients_update_all')) {
            return true;
        }

        if ($user->hasPermissionTo('clients_update_own')) {
            return $client->user_id === $user->id;
        }

        return $user->hasPermissionTo('clients_update');
    }

    /**
     * Определить, может ли пользователь удалять клиента
     *
     * @param User $user
     * @param Client $client
     * @return bool
     */
    public function delete(User $user, Client $client): bool
    {
        if ($user->hasPermissionTo('clients_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('clients_delete_own')) {
            return $client->user_id === $user->id;
        }

        return $user->hasPermissionTo('clients_delete');
    }

    /**
     * Определить, может ли пользователь просматривать баланс клиента
     *
     * @param User $user
     * @param Client $client
     * @return bool
     */
    public function viewBalance(User $user, Client $client): bool
    {
        return $user->hasPermissionTo('settings_client_balance_view');
    }
}

