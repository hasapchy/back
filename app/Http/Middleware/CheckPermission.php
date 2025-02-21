<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    // CheckPermission.php
public function handle(Request $request, Closure $next, $permission = null)
{
    $user = auth()->user();

    // Проверка активности
    if (!$user || !$user->is_active) {
        abort(403, 'Ваш аккаунт неактивен.');
    }

    // Проверка права
    if ($permission && !$user->hasPermission($permission)) {
        abort(403, 'У вас нет прав для выполнения этого действия.');
    }

    return $next($request);
}

}
