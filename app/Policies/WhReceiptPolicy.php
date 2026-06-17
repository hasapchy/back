<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhReceipt;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class WhReceiptPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'warehouse_receipts';

    /**
     * Determine whether the user can view any warehouse receipts.
     */
    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр приходов');
    }

    /**
     * Determine whether the user can view the warehouse receipt.
     */
    public function view(User $user, WhReceipt $receipt): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $receipt, 'У вас нет прав на просмотр этого прихода');
    }

    /**
     * Determine whether the user can create warehouse receipts.
     */
    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание приходов');
    }

    /**
     * Determine whether the user can update the warehouse receipt.
     */
    public function update(User $user, WhReceipt $receipt): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $receipt, 'У вас нет прав на редактирование этого прихода');
    }

    /**
     * Determine whether the user can delete the warehouse receipt.
     */
    public function delete(User $user, WhReceipt $receipt): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $receipt, 'У вас нет прав на удаление этого прихода');
    }
}
