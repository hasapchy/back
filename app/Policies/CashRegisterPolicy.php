<?php

namespace App\Policies;

use App\Models\CashRegister;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use App\Support\SimpleUser;
use Illuminate\Auth\Access\Response;

class CashRegisterPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'cash_registers';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр касс');
    }

    public function view(User $user, CashRegister $cashRegister): Response
    {
        if (SimpleUser::matches($user) && $cashRegister->hasUser($user->id)) {
            return Response::allow();
        }

        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $cashRegister, 'У вас нет прав на эту кассу');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание касс');
    }

    public function update(User $user, CashRegister $cashRegister): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $cashRegister, 'У вас нет прав на редактирование этой кассы');
    }

    public function delete(User $user, CashRegister $cashRegister): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $cashRegister, 'У вас нет прав на удаление этой кассы');
    }
}
