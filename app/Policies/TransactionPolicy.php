<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Transaction;

class TransactionPolicy
{
    /**
     * Определить, может ли пользователь просматривать список транзакций
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('transactions_view_all') 
            || $user->hasPermissionTo('transactions_view_own')
            || $user->hasPermissionTo('transactions_view');
    }

    /**
     * Определить, может ли пользователь просматривать транзакцию
     *
     * @param User $user
     * @param Transaction $transaction
     * @return bool
     */
    public function view(User $user, Transaction $transaction): bool
    {
        if ($user->hasPermissionTo('transactions_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('transactions_view_own')) {
            return $transaction->user_id === $user->id;
        }

        return $user->hasPermissionTo('transactions_view');
    }

    /**
     * Определить, может ли пользователь создавать транзакции
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('transactions_create');
    }

    /**
     * Определить, может ли пользователь обновлять транзакцию
     *
     * @param User $user
     * @param Transaction $transaction
     * @return bool
     */
    public function update(User $user, Transaction $transaction): bool
    {
        if ($user->hasPermissionTo('transactions_update_all')) {
            return true;
        }

        if ($user->hasPermissionTo('transactions_update_own')) {
            return $transaction->user_id === $user->id;
        }

        return $user->hasPermissionTo('transactions_update');
    }

    /**
     * Определить, может ли пользователь удалять транзакцию
     *
     * @param User $user
     * @param Transaction $transaction
     * @return bool
     */
    public function delete(User $user, Transaction $transaction): bool
    {
        if ($user->hasPermissionTo('transactions_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('transactions_delete_own')) {
            return $transaction->user_id === $user->id;
        }

        return $user->hasPermissionTo('transactions_delete');
    }

    /**
     * Определить, может ли пользователь корректировать баланс клиента
     *
     * @param User $user
     * @return bool
     */
    public function adjustBalance(User $user): bool
    {
        return $user->hasPermissionTo('settings_client_balance_adjustment');
    }
}

