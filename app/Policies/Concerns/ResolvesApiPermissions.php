<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\CompanyScopedPermissions;

trait ResolvesApiPermissions
{
    /**
     * @return array<int, string>
     */
    protected function permissionsForRequest(User $user): array
    {
        return CompanyScopedPermissions::names($user);
    }
}
