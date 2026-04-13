<?php

namespace App\Services;

use App\Models\User;

class MobileSanctumTokenService
{
    /**
     * @return array<string, mixed>
     */
    public function issueTokenPair(User $user, bool $remember, int $companyId): array
    {
        $ttl = (int) ($remember
            ? config('sanctum.remember_expiration', 10080)
            : config('sanctum.expiration', 1440));
        $expiresAt = $ttl > 0 ? now()->addMinutes($ttl) : null;

        $refreshDays = $remember
            ? (int) config('sanctum.mobile_refresh_days_remember', 30)
            : (int) config('sanctum.mobile_refresh_days', 7);
        $refreshUntil = now()->addDays($refreshDays);

        $access = $user->createToken('access-token', ['*'], $expiresAt);
        $refresh = $user->createToken('refresh-token', ['refresh'], $refreshUntil);

        $access->accessToken->forceFill(['company_id' => $companyId])->save();
        $refresh->accessToken->forceFill(['company_id' => $companyId])->save();

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'token_type' => 'bearer',
            'expires_in' => $ttl > 0 ? $ttl * 60 : null,
            'refresh_expires_in' => $refreshDays * 86400,
        ];
    }
}
