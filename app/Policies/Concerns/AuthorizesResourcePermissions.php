<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Services\PermissionCheckService;
use Illuminate\Auth\Access\Response;

trait AuthorizesResourcePermissions
{
    use ResolvesApiPermissions;

    protected function resourceAbilityResponse(
        User $user,
        string $resourceConfigKey,
        string $action,
        mixed $record,
        string $denyMessage
    ): Response {
        if ($user->is_admin) {
            return Response::allow();
        }

        $permissions = $this->permissionsForRequest($user);
        $ok = app(PermissionCheckService::class)->canPerformAction(
            $user,
            $resourceConfigKey,
            $action,
            $record,
            $permissions
        );

        return $ok ? Response::allow() : Response::deny($denyMessage);
    }

    protected function allowsResourceAction(
        User $user,
        string $resourceConfigKey,
        string $action,
        mixed $record = null
    ): bool {
        if ($user->is_admin) {
            return true;
        }

        $permissions = $this->permissionsForRequest($user);

        return app(PermissionCheckService::class)->canPerformAction(
            $user,
            $resourceConfigKey,
            $action,
            $record,
            $permissions
        );
    }
}
