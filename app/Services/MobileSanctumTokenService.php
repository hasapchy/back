<?php

namespace App\Services;

use App\Enums\TokenClient;
use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Http\Request;

class MobileSanctumTokenService
{
    public function __construct(
        private readonly UserAuthSessionService $authSessionService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function issueTokenPair(
        User $user,
        bool $remember,
        int $companyId,
        Request $request,
        ?UserAuthSession $authSession = null,
    ): array {
        $ttl = (int) ($remember
            ? config('sanctum.remember_expiration', 10080)
            : config('sanctum.expiration', 1440));
        $expiresAt = $ttl > 0 ? now()->addMinutes($ttl) : null;

        $refreshDays = $remember
            ? (int) config('sanctum.mobile_refresh_days_remember', 30)
            : (int) config('sanctum.mobile_refresh_days', 7);
        $refreshUntil = now()->addDays($refreshDays);

        if ($authSession === null) {
            $authSession = $this->authSessionService->createForLogin(
                $user,
                $request,
                TokenClient::Mobile,
                $refreshUntil,
            );
        }

        $access = $user->createToken('access-token', ['*'], $expiresAt);
        $refresh = $user->createToken('refresh-token', ['refresh'], $refreshUntil);

        $clientAttrs = [
            'auth_session_id' => $authSession->id,
            'company_id' => $companyId,
            'client_type' => TokenClient::Mobile->value,
            'device_fingerprint' => $authSession->device_fingerprint,
            'device_name' => $authSession->device_name,
        ];
        $access->accessToken->forceFill($clientAttrs)->save();
        $refresh->accessToken->forceFill($clientAttrs)->save();

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'token_type' => 'bearer',
            'expires_in' => $ttl > 0 ? $ttl * 60 : null,
            'refresh_expires_in' => $refreshDays * 86400,
            'auth_session_id' => $authSession->id,
        ];
    }
}
