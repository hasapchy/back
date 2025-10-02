<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CheckTimeRestriction
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @param  string  $model
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next, string $model)
    {
        $user = auth('api')->user();
        
        // Если пользователь администратор, пропускаем проверку
        if ($user && $user->is_admin) {
            return $next($request);
        }

        // Получаем ID из маршрута
        $id = $request->route('id');
        if (!$id) {
            return $next($request);
        }

        // Получаем модель и запись
        $modelClass = "App\\Models\\{$model}";
        if (!class_exists($modelClass)) {
            return $next($request);
        }

        $record = $modelClass::find($id);
        if (!$record) {
            return $next($request);
        }

        // Проверяем, прошло ли 24 часа с момента создания
        $createdAt = Carbon::parse($record->created_at);
        $now = Carbon::now();
        $hoursPassed = $createdAt->diffInHours($now);

        if ($hoursPassed >= 24) {
            return response()->json([
                'message' => 'Редактирование и удаление записей возможно только в течение 24 часов с момента создания'
            ], 403);
        }

        return $next($request);
    }
}
