<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Throwable;

final class AuthRequestLogContext
{
    private const AUTH_CHANNEL = 'auth';

    public static function enabled(): bool
    {
        return filter_var(env('AUTH_LOG', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function verbose(): bool
    {
        return filter_var(env('AUTH_LOG_VERBOSE', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function logAuth(string $level, string $message, array $context = []): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $channel = Log::channel(self::AUTH_CHANNEL);
            if ($level === 'warning') {
                $channel->warning($message, $context);
            } else {
                $channel->info($message, $context);
            }
        } catch (Throwable) {
            try {
                Log::info('[auth:fallback] '.$message, array_merge(['_level' => $level], $context));
            } catch (Throwable) {
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function forUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return [
            'user_id' => $user->id,
            'email' => $user->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        $sessionCookie = config('session.cookie');
        $rememberCookie = self::rememberCookieName();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        return [
            'ip' => $request->ip(),
            'host' => $request->getHost(),
            'forwarded_for' => $request->header('X-Forwarded-For'),
            'origin' => mb_substr((string) $request->header('Origin'), 0, 200),
            'referer' => mb_substr((string) $request->header('Referer'), 0, 200),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
            'stateful_frontend' => EnsureFrontendRequestsAreStateful::fromFrontend($request),
            'sanctum_stateful_domain_count' => count(config('sanctum.stateful', [])),
            'session_cookie_name' => $sessionCookie,
            'remember_cookie_name' => $rememberCookie,
            'has_session_cookie' => is_string($sessionCookie) && $sessionCookie !== '' && $request->hasCookie($sessionCookie),
            'has_remember_cookie' => is_string($rememberCookie) && $rememberCookie !== '' && $request->hasCookie($rememberCookie),
            'has_xsrf_cookie' => $request->hasCookie('XSRF-TOKEN'),
            'has_bearer_token' => $request->bearerToken() !== null && $request->bearerToken() !== '',
            'laravel_session_id_prefix' => is_string($sessionId) && $sessionId !== ''
                ? mb_substr($sessionId, 0, 8)
                : null,
            'device_fingerprint' => self::deviceFingerprint($request),
            'session_lifetime_minutes' => (int) config('session.lifetime', 120),
        ];
    }

    private static function rememberCookieName(): ?string
    {
        try {
            return Auth::guard('web')->getRecallerName();
        } catch (Throwable) {
            return null;
        }
    }

    private static function deviceFingerprint(Request $request): ?string
    {
        $value = $request->header('X-Device-Fingerprint');
        if (! is_string($value)) {
            return null;
        }
        $value = mb_substr(trim($value), 0, 64);

        return $value !== '' ? $value : null;
    }
}
