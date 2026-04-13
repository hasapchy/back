<?php

namespace App\Http\Middleware;

use App\Models\Sanctum\PersonalAccessToken;
use App\Support\ResolvedCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompanyContext
{
    /**
     * Выставляет resolved company: PAT, сессия SPA, заголовок. Если Sanctum отдал TransientToken из web-guard, company_id читается из Bearer PAT того же пользователя.
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

        $fromToken = null;
        $accessToken = $user->currentAccessToken();
        if ($accessToken instanceof PersonalAccessToken && $accessToken->company_id !== null && $accessToken->company_id !== '') {
            $fromToken = (int) $accessToken->company_id;
        } elseif (! $accessToken instanceof PersonalAccessToken) {
            $bearer = $request->bearerToken();
            if (is_string($bearer) && $bearer !== '') {
                $pat = PersonalAccessToken::findToken($bearer);
                $tokenable = $pat instanceof PersonalAccessToken ? $pat->tokenable : null;
                if ($tokenable && $user->is($tokenable)
                    && $pat->company_id !== null && $pat->company_id !== '') {
                    $fromToken = (int) $pat->company_id;
                }
            }
        }

        $fromSession = null;
        if ($request->hasSession() && $request->session()->isStarted()) {
            $sid = $request->session()->get(ResolvedCompany::SESSION_KEY);
            if ($sid !== null && $sid !== '' && is_numeric($sid)) {
                $fromSession = (int) $sid;
            }
        }

        $headerId = ResolvedCompany::fromHeaderOnly($request);

        $resolved = $fromToken ?? $fromSession;

        if ($resolved !== null && $headerId !== null && $resolved !== $headerId) {
            return response()->json(['message' => 'Company context mismatch'], 409);
        }

        if ($resolved === null && $headerId !== null) {
            $resolved = $headerId;
        }

        $request->attributes->set(ResolvedCompany::ATTRIBUTE, $resolved);

        return $next($request);
    }
}
