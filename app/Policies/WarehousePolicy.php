<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class WarehousePolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'warehouses';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр складов');
    }

    public function view(User $user, Warehouse $warehouse): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $warehouse, 'У вас нет прав на этот склад');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание складов');
    }

    public function update(User $user, Warehouse $warehouse): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $warehouse, 'У вас нет прав на редактирование этого склада');
    }

    public function delete(User $user, Warehouse $warehouse): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $warehouse, 'У вас нет прав на удаление этого склада');
    }
}
