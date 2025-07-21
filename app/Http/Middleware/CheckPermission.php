<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
       $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (!Gate::allows($permission)) {
            return response()->json(['message' => 'Нет доступа'], 403);
        }

        return $next($request);
    }
}
