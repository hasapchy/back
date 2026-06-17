<?php

namespace App\Policies;

use App\Models\CashTransfer;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class TransferPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'transfers';

    /**
     * Determine whether the user can view any transfers.
     */
    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр переводов');
    }

    /**
     * Determine whether the user can view the transfer.
     */
    public function view(User $user, CashTransfer $transfer): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $transfer, 'У вас нет прав на просмотр этого перевода');
    }

    /**
     * Determine whether the user can create transfers.
     */
    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание переводов');
    }

    /**
     * Determine whether the user can update the transfer.
     */
    public function update(User $user, CashTransfer $transfer): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $transfer, 'У вас нет прав на редактирование этого перевода');
    }

    /**
     * Determine whether the user can delete the transfer.
     */
    public function delete(User $user, CashTransfer $transfer): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $transfer, 'У вас нет прав на удаление этого перевода');
    }
}
