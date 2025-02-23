<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{

public function handle(Request $request, Closure $next)
{
    $user = auth()->user();

    // Проверка активности
    if (!$user || !$user->is_active) {
        abort(403, 'Ваш аккаунт неактивен.');
    }

    return $next($request);
}

}
