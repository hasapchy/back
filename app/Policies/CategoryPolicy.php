<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class CategoryPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'categories';

    /**
     * Determine whether the user can view any categories.
     */
    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр категорий');
    }

    /**
     * Determine whether the user can view the category.
     */
    public function view(User $user, Category $category): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $category, 'У вас нет прав на просмотр этой категории');
    }

    /**
     * Determine whether the user can create categories.
     */
    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание категорий');
    }

    /**
     * Determine whether the user can update the category.
     */
    public function update(User $user, Category $category): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $category, 'У вас нет прав на редактирование этой категории');
    }

    /**
     * Determine whether the user can delete the category.
     */
    public function delete(User $user, Category $category): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $category, 'У вас нет прав на удаление этой категории');
    }
}
