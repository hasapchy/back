<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($user->is_admin) {
            return $next($request);
        }

        if (!Gate::allows($permission)) {
            return response()->json(['message' => 'Нет доступа'], 403);
        }

        return $next($request);
    }
}
