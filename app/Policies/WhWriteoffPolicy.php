<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhWriteoff;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class WhWriteoffPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'warehouse_writeoffs';

    /**
     * Determine whether the user can view any warehouse write-offs.
     */
    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр списаний');
    }

    /**
     * Determine whether the user can view the warehouse write-off.
     */
    public function view(User $user, WhWriteoff $writeoff): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $writeoff, 'У вас нет прав на просмотр этого списания');
    }

    /**
     * Determine whether the user can create warehouse write-offs.
     */
    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание списаний');
    }

    /**
     * Determine whether the user can update the warehouse write-off.
     */
    public function update(User $user, WhWriteoff $writeoff): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $writeoff, 'У вас нет прав на редактирование этого списания');
    }

    /**
     * Determine whether the user can delete the warehouse write-off.
     */
    public function delete(User $user, WhWriteoff $writeoff): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $writeoff, 'У вас нет прав на удаление этого списания');
    }
}
