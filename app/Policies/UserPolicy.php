<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'users';

    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, User $model): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $model, 'Нет прав на просмотр этого пользователя');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'Нет прав на создание пользователей');
    }

    public function update(User $user, User $model): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $model, 'Нет прав на редактирование этого пользователя');
    }

    public function delete(User $user, User $model): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $model, 'Нет прав на удаление этого пользователя');
    }
}
