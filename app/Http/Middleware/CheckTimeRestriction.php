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
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function handle(Request $request, Closure $next, string $model)
    {
        $user = $request->user();

        if ($user && $user->is_admin) {
            return $next($request);
        }

        $id = $request->route('id');
        if (! $id) {
            return $next($request);
        }

        $modelClass = "App\\Models\\{$model}";
        if (! class_exists($modelClass)) {
            return $next($request);
        }

        $record = $modelClass::find($id);
        if (! $record) {
            return $next($request);
        }

        $createdAt = Carbon::parse($record->created_at);
        if ($createdAt->diffInHours(Carbon::now()) >= 24) {
            return response()->json([
                'message' => 'Удаление записей возможно только в течение 24 часов с момента создания',
            ], 403);
        }

        return $next($request);
    }
}
