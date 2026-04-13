<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class ClientPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'clients';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр клиентов');
    }

    public function view(User $user, Client $client): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $client, 'У вас нет прав на просмотр этого клиента');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание клиентов');
    }

    public function update(User $user, Client $client): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $client, 'У вас нет прав на редактирование этого клиента');
    }

    public function delete(User $user, Client $client): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $client, 'У вас нет прав на удаление этого клиента');
    }
}
