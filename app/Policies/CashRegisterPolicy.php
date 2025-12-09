<?php

namespace App\Policies;

use App\Models\CashRegister;
use App\Models\User;
use App\Services\PermissionCheckService;

class CashRegisterPolicy
{
    public function view(User $user, CashRegister $cashRegister): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $permissions = $this->getUserPermissions($user);
        $permissionCheckService = new PermissionCheckService();
        
        return $permissionCheckService->canPerformAction(
            $user,
            'cash_registers',
            'view',
            $cashRegister,
            $permissions
        );
    }

    protected function getUserPermissions(User $user): array
    {
        $companyId = request()->header('X-Company-ID');
        
        if ($companyId) {
            return $user->getAllPermissionsForCompany((int)$companyId)->pluck('name')->toArray();
        }
        
        return $user->getAllPermissions()->pluck('name')->toArray();
    }
}

