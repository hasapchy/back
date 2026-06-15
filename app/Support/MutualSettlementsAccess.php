<?php

namespace App\Support;

use App\Models\User;

class MutualSettlementsAccess
{
    /**
     * @return array<int, string>
     */
    public static function getAllowedClientTypes(?User $user): array
    {
        if (! $user) {
            return [];
        }

        if ($user->is_admin) {
            return ['individual', 'company', 'employee', 'investor'];
        }

        $names = CompanyScopedPermissions::names($user);
        $allowedTypes = [];
        $config = config('permissions.resources.mutual_settlements');

        if (isset($config['custom_permissions'])) {
            foreach ($config['custom_permissions'] as $key => $permissionName) {
                if (in_array($permissionName, $names, true)) {
                    $allowedTypes[] = str_replace('view_', '', $key);
                }
            }
        }

        return $allowedTypes;
    }
}
