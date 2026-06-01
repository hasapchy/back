<?php

namespace App\Services;

use App\Enums\TokenClient;
use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class UserAuthSessionService
{
    /**
     * @return UserAuthSession
     */
    public function createForLogin(User $user, Request $request, TokenClient $client, ?Carbon $expiresAt = null): UserAuthSession
    {
        $fingerprint = $this->deviceFingerprint($request) ?? Str::uuid()->toString();
        $userAgent = $request->userAgent();
        $userAgent = is_string($userAgent) ? mb_substr($userAgent, 0, 500) : null;

        return UserAuthSession::query()->create([
            'user_id' => $user->id,
            'client_type' => $client->value,
            'device_fingerprint' => $fingerprint,
            'device_name' => $this->deviceName($request) ?? $userAgent,
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent,
            'last_activity_at' => now(),
            'expires_at' => $expiresAt,
            'laravel_session_id' => $client === TokenClient::Web && $request->hasSession()
                ? $request->session()->getId()
                : null,
        ]);
    }

    /**
     * @return int|null
     */
    public function resolveCurrentSessionId(Request $request, ?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        if ($request->hasSession()) {
            $fromSession = $request->session()->get('auth_session_id');
            if (is_numeric($fromSession)) {
                return (int) $fromSession;
            }
        }

        $token = $user->currentAccessToken();
        if ($token !== null && $token->auth_session_id !== null) {
            return (int) $token->auth_session_id;
        }

        return null;
    }

    public function touchActivity(?int $authSessionId): void
    {
        if ($authSessionId === null || $authSessionId < 1) {
            return;
        }

        UserAuthSession::query()
            ->whereKey($authSessionId)
            ->update(['last_activity_at' => now()]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, UserAuthSession>
     */
    public function listForUser(int $userId)
    {
        return UserAuthSession::query()
            ->where('user_id', $userId)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get();
    }

    public function deleteAllForUser(int $userId, ?TokenClient $client = null): void
    {
        $query = UserAuthSession::query()->where('user_id', $userId);
        if ($client !== null) {
            $query->where('client_type', $client->value);
        }

        foreach ($query->get() as $session) {
            $this->destroyLaravelSessionFile($session->laravel_session_id);
        }

        $query->delete();
    }

    public function resetMobileAuth(User $user): void
    {
        $user->deleteTokensForClient(TokenClient::Mobile);
        $this->deleteAllForUser((int) $user->id, TokenClient::Mobile);
    }

    public function invalidateWebSession(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function destroyLaravelSessionFile(?string $laravelSessionId): void
    {
        if ($laravelSessionId === null || $laravelSessionId === '' || config('session.driver') !== 'file') {
            return;
        }

        $path = config('session.files').'/'.$laravelSessionId;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function deviceFingerprint(Request $request): ?string
    {
        $value = $request->header('X-Device-Fingerprint');
        if (! is_string($value)) {
            return null;
        }
        $value = mb_substr(trim($value), 0, 64);

        return $value !== '' ? $value : null;
    }

    private function deviceName(Request $request): ?string
    {
        $value = $request->header('X-Device-Name');
        if (is_string($value)) {
            $value = mb_substr(trim($value), 0, 255);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
