<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CompanyScopedPermissionGate;
use App\Support\PermissionDeniedLogger;
use App\Support\ResolvedCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionWithScopeMiddleware
{
    /**
     * @param  \Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($user->is_admin) {
            return $next($request);
        }

        $companyId = ResolvedCompany::fromRequest($request);
        $permissionList = [];
        foreach ($permissions as $perm) {
            $permissionList = array_merge($permissionList, explode(',', $perm));
        }
        $permissionList = array_map('trim', $permissionList);

        if (CompanyScopedPermissionGate::allowsAny($user, $companyId, $permissionList)) {
            return $next($request);
        }

        $userPermissions = $companyId
            ? $user->getAllPermissionsForCompany($companyId)->pluck('name')->toArray()
            : $user->getAllPermissions()->pluck('name')->toArray();

        PermissionDeniedLogger::log(
            $request,
            'permission.scope',
            $permissionList,
            $user,
            $companyId,
            $userPermissions
        );

        return response()->json(['message' => 'Forbidden'], 403);
    }
}
