<?php

namespace App\Http\Middleware;

use App\Support\ResolvedCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionWithScopeMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        /** @var \App\Models\User|null $user */
        $user = auth('api')->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($user->is_admin) {
            return $next($request);
        }

        $companyId = ResolvedCompany::fromRequest($request);
        $userPermissions = $companyId
            ? $user->getAllPermissionsForCompany($companyId)->pluck('name')->toArray()
            : $user->getAllPermissions()->pluck('name')->toArray();

        $permissionList = [];
        foreach ($permissions as $perm) {
            $permissionList = array_merge($permissionList, explode(',', $perm));
        }
        $permissionList = array_map('trim', $permissionList);

        foreach ($permissionList as $permission) {
            if (in_array($permission, $userPermissions, true)) {
                return $next($request);
            }

            if (str_ends_with($permission, '_all')) {
                $ownPermission = str_replace('_all', '_own', $permission);
                if (in_array($ownPermission, $userPermissions, true)) {
                    return $next($request);
                }
            }

            if (str_ends_with($permission, '_own')) {
                $allPermission = str_replace('_own', '_all', $permission);
                if (in_array($allPermission, $userPermissions, true)) {
                    return $next($request);
                }
            }

            $oldPermission = preg_replace('/_(all|own)$/', '', $permission);
            if ($oldPermission !== $permission && in_array($oldPermission, $userPermissions, true)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }
}
