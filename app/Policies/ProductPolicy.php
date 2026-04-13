<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'products';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр товаров');
    }

    public function view(User $user, Product $product): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $product, 'У вас нет прав на просмотр этого товара');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание товаров');
    }

    public function update(User $user, Product $product): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $product, 'У вас нет прав на редактирование этого товара');
    }

    public function delete(User $user, Product $product): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $product, 'У вас нет прав на удаление этого товара');
    }
}
