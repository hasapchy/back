<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class TaskPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'tasks';

    /**
     * Determine whether the user can view any tasks.
     */
    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр задач');
    }

    /**
     * Determine whether the user can view the task.
     */
    public function view(User $user, Task $task): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $task, 'У вас нет прав на просмотр этой задачи');
    }

    /**
     * Determine whether the user can create tasks.
     */
    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание задач');
    }

    /**
     * Determine whether the user can update the task.
     */
    public function update(User $user, Task $task): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $task, 'У вас нет прав на редактирование этой задачи');
    }

    /**
     * Determine whether the user can delete the task.
     */
    public function delete(User $user, Task $task): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $task, 'У вас нет прав на удаление этой задачи');
    }

    /**
     * Determine whether the user can restore the task.
     */
    public function restore(User $user, Task $task): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $task, 'У вас нет прав на восстановление этой задачи');
    }
}
