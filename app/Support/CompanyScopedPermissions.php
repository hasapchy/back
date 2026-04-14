<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Permission;

final class CompanyScopedPermissions
{
    public static function names(User $user): array
    {
        if ($user->is_admin) {
            return Permission::where('guard_name', 'api')->pluck('name')->toArray();
        }

        return self::namesForCompany($user, ResolvedCompany::fromRequest(request()));
    }

    public static function namesForCompany(User $user, ?int $companyId): array
    {
        if ($user->is_admin) {
            return Permission::where('guard_name', 'api')->pluck('name')->toArray();
        }

        if ($companyId) {
            return $user->getAllPermissionsForCompany($companyId)->pluck('name')->toArray();
        }

        return $user->getAllPermissions()->pluck('name')->toArray();
    }

    public static function userHas(User $user, string $permission): bool
    {
        return in_array($permission, self::names($user), true);
    }

    public static function userHasAny(User $user, array $permissions): bool
    {
        $names = self::names($user);
        foreach ($permissions as $permission) {
            if (in_array($permission, $names, true)) {
                return true;
            }
        }

        return false;
    }

    public static function userCanViewCurrencyHistory(User $user): bool
    {
        return self::userHasAny($user, [
            'currency_history_view',
            'currency_history_view_all',
            'currency_history_view_own',
        ]);
    }
}
