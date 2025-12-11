<?php

namespace App\Rules;

use App\Models\Project;
use App\Services\PermissionCheckService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ProjectAccessRule implements ValidationRule
{
    protected $user;

    public function __construct($user = null)
    {
        $this->user = $user ?? auth('api')->user();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value) {
            return;
        }

        if (!$this->user) {
            $fail('Поле :attribute некорректно. Пользователь не авторизован.');
            return;
        }

        $project = Project::find($value);

        if (!$project) {
            return;
        }

        $companyId = request()->header('X-Company-ID');
        if ($companyId && $project->company_id != $companyId) {
            $fail('Проект не принадлежит текущей компании.');
            return;
        }

        if ($this->user->is_admin) {
            return;
        }

        $permissions = $this->getUserPermissions();
        $permissionCheckService = new PermissionCheckService();

        $hasAccess = $permissionCheckService->canPerformAction(
            $this->user,
            'projects',
            'view',
            $project,
            $permissions
        );

        if (!$hasAccess) {
            $fail('У вас нет доступа к этому проекту.');
        }
    }

    protected function getUserPermissions(): array
    {
        $companyId = request()->header('X-Company-ID');

        if ($companyId) {
            return $this->user->getAllPermissionsForCompany((int)$companyId)->pluck('name')->toArray();
        }

        return $this->user->getAllPermissions()->pluck('name')->toArray();
    }
}

