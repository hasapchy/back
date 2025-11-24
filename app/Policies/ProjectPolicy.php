<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Project;

class ProjectPolicy
{
    /**
     * Определить, может ли пользователь просматривать список проектов
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('projects_view_all') 
            || $user->hasPermissionTo('projects_view_own')
            || $user->hasPermissionTo('projects_view');
    }

    /**
     * Определить, может ли пользователь просматривать проект
     *
     * @param User $user
     * @param Project $project
     * @return bool
     */
    public function view(User $user, Project $project): bool
    {
        if ($user->hasPermissionTo('projects_view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('projects_view_own')) {
            return $project->user_id === $user->id;
        }

        return $user->hasPermissionTo('projects_view');
    }

    /**
     * Определить, может ли пользователь создавать проекты
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('projects_create');
    }

    /**
     * Определить, может ли пользователь обновлять проект
     *
     * @param User $user
     * @param Project $project
     * @return bool
     */
    public function update(User $user, Project $project): bool
    {
        if ($user->hasPermissionTo('projects_update_all')) {
            return true;
        }

        if ($user->hasPermissionTo('projects_update_own')) {
            return $project->user_id === $user->id;
        }

        return $user->hasPermissionTo('projects_update');
    }

    /**
     * Определить, может ли пользователь удалять проект
     *
     * @param User $user
     * @param Project $project
     * @return bool
     */
    public function delete(User $user, Project $project): bool
    {
        if ($user->hasPermissionTo('projects_delete_all')) {
            return true;
        }

        if ($user->hasPermissionTo('projects_delete_own')) {
            return $project->user_id === $user->id;
        }

        return $user->hasPermissionTo('projects_delete');
    }
}

