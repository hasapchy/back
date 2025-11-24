<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBasementWorker
{
    /**
     * Handle an incoming request and ensure user has basement role (global or company-specific).
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api') ?? $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $basementRole = config('basement.worker_role', 'basement_worker');
        $roleNames = method_exists($user, 'getAllRoleNames')
            ? $user->getAllRoleNames()
            : $user->getRoleNames()->toArray();

        if (!in_array($basementRole, $roleNames, true)) {
            return response()->json([
                'error' => 'Access denied',
                'message' => 'Basement access is restricted to basement workers',
            ], 403);
        }

        return $next($request);
    }
}

