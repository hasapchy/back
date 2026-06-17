<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class UnitPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'units';

    /**
     * Determine whether the user can view any units.
     */
    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр единиц измерения');
    }

    /**
     * Determine whether the user can view the unit.
     */
    public function view(User $user, Unit $unit): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $unit, 'У вас нет прав на просмотр этой единицы измерения');
    }

    /**
     * Determine whether the user can create units.
     */
    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание единиц измерения');
    }

    /**
     * Determine whether the user can update the unit.
     */
    public function update(User $user, Unit $unit): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $unit, 'У вас нет прав на редактирование этой единицы измерения');
    }

    /**
     * Determine whether the user can delete the unit.
     */
    public function delete(User $user, Unit $unit): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $unit, 'У вас нет прав на удаление этой единицы измерения');
    }
}
