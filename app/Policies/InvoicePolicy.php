<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class InvoicePolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'invoices';

    /**
     * Determine whether the user can view any invoices.
     */
    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр счетов');
    }

    /**
     * Determine whether the user can view the invoice.
     */
    public function view(User $user, Invoice $invoice): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $invoice, 'У вас нет прав на просмотр этого счёта');
    }

    /**
     * Determine whether the user can create invoices.
     */
    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание счетов');
    }

    /**
     * Determine whether the user can update the invoice.
     */
    public function update(User $user, Invoice $invoice): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $invoice, 'У вас нет прав на редактирование этого счёта');
    }

    /**
     * Determine whether the user can delete the invoice.
     */
    public function delete(User $user, Invoice $invoice): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $invoice, 'У вас нет прав на удаление этого счёта');
    }
}
