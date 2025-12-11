<?php

namespace App\Rules;

use App\Models\Warehouse;
use App\Services\PermissionCheckService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class WarehouseAccessRule implements ValidationRule
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

        $warehouse = Warehouse::find($value);

        if (!$warehouse) {
            return;
        }

        if ($this->user->is_admin) {
            return;
        }

        $permissions = $this->getUserPermissions();
        $permissionCheckService = new PermissionCheckService();

        $hasAccess = $permissionCheckService->canPerformAction(
            $this->user,
            'warehouses',
            'view',
            $warehouse,
            $permissions
        );

        if (!$hasAccess) {
            $fail('У вас нет доступа к этому складу.');
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

