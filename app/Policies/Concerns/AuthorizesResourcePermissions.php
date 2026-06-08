<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Services\PermissionCheckService;
use App\Support\ResolvedCompany;
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
        if ($this->recordBelongsToAnotherCompany($record)) {
            return Response::deny($denyMessage);
        }

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
        if ($this->recordBelongsToAnotherCompany($record)) {
            return false;
        }

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

    /**
     * @param mixed $record
     * @return bool
     */
    private function recordBelongsToAnotherCompany(mixed $record): bool
    {
        if (! is_object($record) || ! isset($record->company_id) || $record->company_id === null) {
            return false;
        }

        $currentCompanyId = ResolvedCompany::fromRequest(request());
        if ($currentCompanyId === null) {
            return true;
        }

        return (int) $record->company_id !== (int) $currentCompanyId;
    }
}
