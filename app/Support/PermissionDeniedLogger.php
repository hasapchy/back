<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class PermissionDeniedLogger
{
    /**
     * @param  array<int, string>  $requiredPermissions
     * @param  array<int, string>  $userPermissionNames
     */
    public static function log(
        Request $request,
        string $middleware,
        array $requiredPermissions,
        ?User $user,
        ?int $companyId,
        array $userPermissionNames,
    ): void {
        $matched = array_values(array_intersect($requiredPermissions, $userPermissionNames));
        $warehouseRelated = array_values(array_filter(
            $userPermissionNames,
            static function (string $p): bool {
                return str_starts_with($p, 'warehouse')
                    || str_starts_with($p, 'inventories');
            }
        ));

        Log::warning('permission.denied', [
            'middleware' => $middleware,
            'method' => $request->method(),
            'path' => $request->path(),
            'required_permissions' => $requiredPermissions,
            'matched_required' => $matched,
            'user_id' => $user?->id,
            'resolved_company_id' => $companyId,
            'user_permission_count' => count($userPermissionNames),
            'warehouse_related_permissions' => $warehouseRelated,
        ]);
    }
}
