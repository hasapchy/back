<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Sanctum\PersonalAccessToken;
use App\Support\ResolvedCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompanyContext
{
    /**
     * Выставляет resolved company из PAT или сессии SPA.
     *
     * @param  \Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            $request->attributes->set(ResolvedCompany::ATTRIBUTE, null);

            return $next($request);
        }

        $fromToken = $this->resolveCompanyIdFromToken($user, $request);

        $fromSession = null;
        if ($request->hasSession() && $request->session()->isStarted()) {
            $sid = $request->session()->get(ResolvedCompany::SESSION_KEY);
            if ($sid !== null && $sid !== '' && is_numeric($sid)) {
                $fromSession = (int) $sid;
            }
        }

        $request->attributes->set(ResolvedCompany::ATTRIBUTE, $fromToken ?? $fromSession);

        return $next($request);
    }

    /**
     * @return int|null
     */
    private function resolveCompanyIdFromToken(User $user, Request $request): ?int
    {
        $accessToken = $user->currentAccessToken();
        if ($accessToken !== null
            && isset($accessToken->company_id)
            && $accessToken->company_id !== null
            && $accessToken->company_id !== '') {
            return (int) $accessToken->company_id;
        }

        $bearer = $request->bearerToken();
        if (! is_string($bearer) || $bearer === '') {
            return null;
        }

        $pat = PersonalAccessToken::findToken($bearer);
        if (! $pat instanceof PersonalAccessToken) {
            return null;
        }

        $tokenable = $pat->tokenable;
        if ($tokenable === null || ! $user->is($tokenable)) {
            return null;
        }

        if ($pat->company_id === null || $pat->company_id === '') {
            return null;
        }

        return (int) $pat->company_id;
    }
}
