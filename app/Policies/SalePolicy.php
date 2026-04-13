<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class SalePolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'sales';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр продаж');
    }

    public function view(User $user, Sale $sale): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $sale, 'У вас нет прав на просмотр этой продажи');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание продаж');
    }

    public function update(User $user, Sale $sale): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $sale, 'У вас нет прав на редактирование этой продажи');
    }

    public function delete(User $user, Sale $sale): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $sale, 'У вас нет прав на удаление этой продажи');
    }
}
