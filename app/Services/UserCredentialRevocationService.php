<?php

namespace App\Services;

use App\Events\UserCredentialsRevoked;
use App\Events\UserSessionRevoked;
use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Http\Request;

class UserCredentialRevocationService
{
    public function __construct(
        private readonly UserAuthSessionService $authSessionService,
    ) {
    }

    public function revokeAll(User $user, ?Request $request = null, string $reason = 'credentials_revoked'): void
    {
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

        $this->authSessionService->destroyLaravelSessionFile($session->laravel_session_id);
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
