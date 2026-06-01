<?php

namespace App\Http\Middleware;

use App\Services\UserAuthSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TouchUserAuthSession
{
    public function __construct(
        private readonly UserAuthSessionService $authSessionService,
    ) {
    }

    /**
     * @param  \Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user === null) {
            return $response;
        }

        $authSessionId = $this->authSessionService->resolveCurrentSessionId($request, $user);
        if ($authSessionId === null) {
            return $response;
        }

        $cacheKey = 'auth_session_touch:'.$authSessionId;
        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, true, 60);
            $this->authSessionService->touchActivity($authSessionId);
        }

        return $response;
    }
}
