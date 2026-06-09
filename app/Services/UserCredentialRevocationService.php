<?php

namespace App\Services;

use App\Events\UserCredentialsRevoked;
use App\Events\UserSessionRevoked;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Support\AuthRequestLogContext;
use Illuminate\Http\Request;

class UserCredentialRevocationService
{
    public function __construct(
        private readonly UserAuthSessionService $authSessionService,
    ) {
    }

    public function revokeAll(User $user, ?Request $request = null, string $reason = 'credentials_revoked'): void
    {
        $sessionsCount = UserAuthSession::query()->where('user_id', $user->id)->count();

        AuthRequestLogContext::logAuth('info', 'auth.credentials.revoked', array_merge(
            $request !== null ? AuthRequestLogContext::fromRequest($request) : [],
            AuthRequestLogContext::forUser($user),
            [
                'reason' => $reason,
                'sessions_count' => $sessionsCount,
                'initiator_user_id' => $request?->user()?->id,
            ]
        ));

        $this->authSessionService->deleteAllForUser((int) $user->id);
        $user->tokens()->delete();

        if ($request !== null && $request->user()?->id === $user->id) {
            $this->authSessionService->invalidateWebSession($request);
        }

        event(new UserCredentialsRevoked($user->id, $reason));
    }

    public function revokeSession(UserAuthSession $session, ?Request $request = null): void
    {
        $sessionId = (int) $session->id;
        $userId = (int) $session->user_id;
        $laravelSessionId = $session->laravel_session_id;

        AuthRequestLogContext::logAuth('info', 'auth.session.revoked', array_merge(
            $request !== null ? AuthRequestLogContext::fromRequest($request) : [],
            [
                'user_id' => $userId,
                'auth_session_id' => $sessionId,
                'client_type' => $session->client_type,
                'laravel_session_id_prefix' => is_string($laravelSessionId) && $laravelSessionId !== ''
                    ? mb_substr($laravelSessionId, 0, 8)
                    : null,
                'initiator_user_id' => $request?->user()?->id,
            ]
        ));

        $this->authSessionService->destroyLaravelSessionFile($laravelSessionId);
        $session->tokens()->delete();
        $session->delete();

        if (
            $request !== null
            && $request->user()?->id === $userId
            && $this->authSessionService->resolveCurrentSessionId($request, $request->user()) === $sessionId
        ) {
            $this->authSessionService->invalidateWebSession($request);
        }

        event(new UserSessionRevoked($userId, $sessionId));
    }
}
