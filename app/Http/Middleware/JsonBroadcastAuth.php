<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Для POST /broadcasting/auth мержит JSON-тело в input:
 * pusher_reverb_flutter шлёт JSON, Broadcast::auth() ожидает form fields.
 */
class JsonBroadcastAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('broadcasting/auth') && $request->isJson()) {
            $request->merge($request->json()->all());
        }
        return $next($request);
    }
}
