<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;

final class GateAbility
{
    public static function allowsAny(?Authorizable $user, string ...$abilities): bool
    {
        if ($user === null) {
            return false;
        }

        foreach ($abilities as $ability) {
            if ($user->can($ability)) {
                return true;
            }
        }

        return false;
    }

    public static function allowsWithScopeAliases(Authorizable $user, string $permission): bool
    {
        $candidates = [$permission];

        if (str_ends_with($permission, '_all')) {
            $candidates[] = str_replace('_all', '_own', $permission);
        }

        if (str_ends_with($permission, '_own')) {
            $candidates[] = str_replace('_own', '_all', $permission);
        }

        $legacy = preg_replace('/_(all|own)$/', '', $permission);
        if ($legacy !== $permission) {
            $candidates[] = $legacy;
        }

        return self::allowsAny($user, ...array_values(array_unique($candidates)));
    }
}
