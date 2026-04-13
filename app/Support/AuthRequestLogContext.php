<?php

namespace App\Support;

use Illuminate\Http\Request;
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
    public static function fromRequest(Request $request): array
    {
        $sessionCookie = config('session.cookie');

        return [
            'ip' => $request->ip(),
            'host' => $request->getHost(),
            'forwarded_for' => $request->header('X-Forwarded-For'),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
            'stateful_frontend' => EnsureFrontendRequestsAreStateful::fromFrontend($request),
            'sanctum_stateful_domain_count' => count(config('sanctum.stateful', [])),
            'has_session_cookie' => is_string($sessionCookie) && $sessionCookie !== '' && $request->hasCookie($sessionCookie),
            'has_bearer_token' => $request->bearerToken() !== null && $request->bearerToken() !== '',
        ];
    }
}
