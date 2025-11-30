<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($user->is_admin) {
            return $next($request);
        }

        $companyId = $request->header('X-Company-ID');
        $userPermissions = $companyId ? $user->getAllPermissionsForCompany((int)$companyId)->pluck('name')->toArray() : $user->getAllPermissions()->pluck('name')->toArray();

        $permissionList = [];
        foreach ($permissions as $perm) {
            $permissionList = array_merge($permissionList, explode(',', $perm));
        }
        $permissionList = array_map('trim', $permissionList);

        foreach ($permissionList as $permission) {
            if (in_array($permission, $userPermissions)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }
}

