<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventBasementAccess
{
    /**
     * Handle an incoming request.
     * Prevents basement workers from accessing main system routes.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\User|null $user */
        $user = auth('api')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if ($user->is_admin) {
            return $next($request);
        }

        $roles = $user->getAllRoleNames();

        if (in_array('basement_worker', $roles, true) && !in_array('admin', $roles, true)) {
            return response()->json([
                'error' => 'Access denied',
                'message' => 'Basement workers cannot access the main system'
            ], 403);
        }

        return $next($request);
    }
}

