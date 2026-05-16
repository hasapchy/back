<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\PermissionDeniedLogger;
use App\Support\ResolvedCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
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
        }

        PermissionDeniedLogger::log(
            $request,
            'permission',
            $permissionList,
            $user,
            $companyId,
            $userPermissions
        );

        return response()->json(['message' => 'Forbidden'], 403);
    }
}
