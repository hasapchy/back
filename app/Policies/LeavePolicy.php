<?php

namespace App\Policies;

use App\Models\Leave;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class LeavePolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'leaves';

    /**
     * Determine whether the user can view any leaves.
     */
    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр отпусков');
    }

    /**
     * Determine whether the user can view the leave.
     */
    public function view(User $user, Leave $leave): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $leave, 'У вас нет прав на просмотр этого отпуска');
    }

    /**
     * Determine whether the user can create leaves.
     */
    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание отпусков');
    }

    /**
     * Determine whether the user can update the leave.
     */
    public function update(User $user, Leave $leave): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $leave, 'У вас нет прав на редактирование этого отпуска');
    }

    /**
     * Determine whether the user can delete the leave.
     */
    public function delete(User $user, Leave $leave): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $leave, 'У вас нет прав на удаление этого отпуска');
    }
}
