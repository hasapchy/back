<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Invoice;

class InvoicePolicy
{
    /**
     * Определить, может ли пользователь просматривать список счетов
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('invoices_view_all') 
            || $user->hasPermissionTo('invoices_view_own')
            || $user->hasPermissionTo('invoices_view');
    }

    /**
     * Определить, может ли пользователь просматривать счет
     *
     * @param User $user
     * @param Invoice $invoice
     * @return bool
     */
    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->hasPermissionTo('invoices_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('invoices_view_own')) {
            return $invoice->user_id === $user->id;
        }

        return $user->hasPermissionTo('invoices_view');
    }

    /**
     * Определить, может ли пользователь создавать счета
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('invoices_create');
    }

    /**
     * Определить, может ли пользователь обновлять счет
     *
     * @param User $user
     * @param Invoice $invoice
     * @return bool
     */
    public function update(User $user, Invoice $invoice): bool
    {
        if ($user->hasPermissionTo('invoices_update_all')) {
            return true;
        }

        if ($user->hasPermissionTo('invoices_update_own')) {
            return $invoice->user_id === $user->id;
        }

        return $user->hasPermissionTo('invoices_update');
    }

    /**
     * Определить, может ли пользователь удалять счет
     *
     * @param User $user
     * @param Invoice $invoice
     * @return bool
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        if ($user->hasPermissionTo('invoices_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('invoices_delete_own')) {
            return $invoice->user_id === $user->id;
        }

        return $user->hasPermissionTo('invoices_delete');
    }
}

