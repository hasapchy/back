<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Product;

class ProductPolicy
{
    /**
     * Определить, может ли пользователь просматривать список товаров
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('products_view_all') 
            || $user->hasPermissionTo('products_view_own')
            || $user->hasPermissionTo('products_view');
    }

    /**
     * Определить, может ли пользователь просматривать товар
     *
     * @param User $user
     * @param Product $product
     * @return bool
     */
    public function view(User $user, Product $product): bool
    {
        if ($user->hasPermissionTo('products_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('products_view_own')) {
            return $product->user_id === $user->id;
        }

        return $user->hasPermissionTo('products_view');
    }

    /**
     * Определить, может ли пользователь создавать товары
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('products_create');
    }

    /**
     * Определить, может ли пользователь создавать временные товары
     *
     * @param User $user
     * @return bool
     */
    public function createTemp(User $user): bool
    {
        return $user->hasPermissionTo('products_create_temp');
    }

    /**
     * Определить, может ли пользователь обновлять товар
     *
     * @param User $user
     * @param Product $product
     * @return bool
     */
    public function update(User $user, Product $product): bool
    {
        if ($user->hasPermissionTo('products_update_all')) {
            return true;
        }

        if ($user->hasPermissionTo('products_update_own')) {
            return $product->user_id === $user->id;
        }

        return $user->hasPermissionTo('products_update');
    }

    /**
     * Определить, может ли пользователь удалять товар
     *
     * @param User $user
     * @param Product $product
     * @return bool
     */
    public function delete(User $user, Product $product): bool
    {
        if ($user->hasPermissionTo('products_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('products_delete_own')) {
            return $product->user_id === $user->id;
        }

        return $user->hasPermissionTo('products_delete');
    }
}

