<?php

namespace App\Policies;

use App\Models\ProjectContract;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class ProjectContractPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'contracts';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр контрактов');
    }

    public function view(User $user, ProjectContract $contract): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $contract, 'У вас нет прав на просмотр этого контракта');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание контрактов');
    }

    public function update(User $user, ProjectContract $contract): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $contract, 'У вас нет прав на редактирование этого контракта');
    }

    public function delete(User $user, ProjectContract $contract): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $contract, 'У вас нет прав на удаление этого контракта');
    }
}
