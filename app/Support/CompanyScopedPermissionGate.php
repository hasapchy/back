<?php

namespace App\Support;

use App\Models\User;

final class CompanyScopedPermissionGate
{
    public static function allowsAny(User $user, ?int $companyId, array $permissionCandidates): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $userPermissions = $companyId
            ? $user->getAllPermissionsForCompany($companyId)->pluck('name')->toArray()
            : $user->getAllPermissions()->pluck('name')->toArray();

        foreach ($permissionCandidates as $permission) {
            if (in_array($permission, $userPermissions, true)) {
                return true;
            }

            if (str_ends_with($permission, '_all')) {
                $ownPermission = str_replace('_all', '_own', $permission);
                if (in_array($ownPermission, $userPermissions, true)) {
                    return true;
                }
            }

            if (str_ends_with($permission, '_own')) {
                $allPermission = str_replace('_own', '_all', $permission);
                if (in_array($allPermission, $userPermissions, true)) {
                    return true;
                }
            }

            $oldPermission = preg_replace('/_(all|own)$/', '', $permission);
            if ($oldPermission !== $permission && in_array($oldPermission, $userPermissions, true)) {
                return true;
            }
        }

        return false;
    }
}
