<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class DepartmentPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'departments';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр отделов');
    }

    public function view(User $user, Department $department): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $department, 'У вас нет прав на просмотр этого отдела');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание отделов');
    }

    public function update(User $user, Department $department): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $department, 'У вас нет прав на редактирование этого отдела');
    }

    public function delete(User $user, Department $department): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $department, 'У вас нет прав на удаление этого отдела');
    }
}
