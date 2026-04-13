<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'projects';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр проектов');
    }

    public function view(User $user, Project $project): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $project, 'У вас нет прав на просмотр этого проекта');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание проектов');
    }

    public function update(User $user, Project $project): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $project, 'У вас нет прав на редактирование этого проекта');
    }

    public function delete(User $user, Project $project): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $project, 'У вас нет прав на удаление этого проекта');
    }
}
