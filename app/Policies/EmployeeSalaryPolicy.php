<?php

namespace App\Policies;

use App\Models\EmployeeSalary;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class EmployeeSalaryPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'employee_salaries';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр зарплат');
    }

    public function view(User $user, EmployeeSalary $salary): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $salary, 'У вас нет прав на просмотр этой записи');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание записей зарплаты');
    }

    public function update(User $user, EmployeeSalary $salary): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $salary, 'У вас нет прав на редактирование этой записи');
    }

    public function delete(User $user, EmployeeSalary $salary): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $salary, 'У вас нет прав на удаление этой записи');
    }
}
