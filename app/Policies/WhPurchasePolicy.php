<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhPurchase;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class WhPurchasePolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'warehouse_purchases';

    /**
     * Determine whether the user can view any warehouse purchases.
     */
    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр закупок');
    }

    /**
     * Determine whether the user can view the warehouse purchase.
     */
    public function view(User $user, WhPurchase $purchase): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $purchase, 'У вас нет прав на просмотр этой закупки');
    }

    /**
     * Determine whether the user can create warehouse purchases.
     */
    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание закупок');
    }

    /**
     * Determine whether the user can update the warehouse purchase.
     */
    public function update(User $user, WhPurchase $purchase): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $purchase, 'У вас нет прав на редактирование этой закупки');
    }

    /**
     * Determine whether the user can delete the warehouse purchase.
     */
    public function delete(User $user, WhPurchase $purchase): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $purchase, 'У вас нет прав на удаление этой закупки');
    }
}
