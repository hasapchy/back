<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionWithScopeMiddleware
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

        // Если передано несколько разрешений через запятую, проверяем каждое
        $permissionList = [];
        foreach ($permissions as $perm) {
            $permissionList = array_merge($permissionList, explode(',', $perm));
        }
        $permissionList = array_map('trim', $permissionList);

        foreach ($permissionList as $permission) {
            // Проверяем точное совпадение разрешения
            if (in_array($permission, $userPermissions)) {
                return $next($request);
            }

            // Если разрешение заканчивается на _all, проверяем также _own
            if (str_ends_with($permission, '_all')) {
                $ownPermission = str_replace('_all', '_own', $permission);
                if (in_array($ownPermission, $userPermissions)) {
                    // Для _own нужно проверить, является ли запись "своей"
                    // Это будет проверяться в контроллере через canPerformAction
                    return $next($request);
                }
            }

            // Если разрешение заканчивается на _own, проверяем также _all
            if (str_ends_with($permission, '_own')) {
                $allPermission = str_replace('_own', '_all', $permission);
                if (in_array($allPermission, $userPermissions)) {
                    return $next($request);
                }
            }

            // Обратная совместимость: проверяем старое разрешение без _all/_own
            $oldPermission = preg_replace('/_(all|own)$/', '', $permission);
            if ($oldPermission !== $permission && in_array($oldPermission, $userPermissions)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }
}

