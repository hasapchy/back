<?php

namespace App\Services\InAppNotifications;

use App\Models\User;
use App\Models\UserNotificationSetting;
use Illuminate\Support\Facades\DB;

class UserNotificationSettingsService
{
    /**
     * @return array<int, array{key: string, enabled: bool}>
     */
    public function mergedForUser(User $user, int $companyId): array
    {
        $row = UserNotificationSetting::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->first();

        $stored = is_array($row?->channels) ? $row->channels : [];
        $out = [];

        foreach ($this->definedChannelKeys() as $key) {
            if (! $this->userMayUseChannel($user, $companyId, $key)) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'enabled' => array_key_exists($key, $stored)
                    ? (bool) $stored[$key]
                    : true,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $patch
     */
    public function updateChannels(User $user, int $companyId, array $patch): void
    {
        $allowedKeys = [];
        foreach ($this->definedChannelKeys() as $key) {
            if ($this->userMayUseChannel($user, $companyId, $key)) {
                $allowedKeys[$key] = true;
            }
        }

        $merged = [];
        $row = UserNotificationSetting::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->first();

        if ($row && is_array($row->channels)) {
            $merged = $row->channels;
        }

        foreach ($patch as $key => $value) {
            if (! isset($allowedKeys[$key])) {
                continue;
            }
            $merged[$key] = (bool) $value;
        }

        foreach ($allowedKeys as $key => $_) {
            if (! array_key_exists($key, $merged)) {
                $merged[$key] = true;
            }
        }

        UserNotificationSetting::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'company_id' => $companyId,
            ],
            ['channels' => $merged]
        );
    }

    public function isChannelEnabled(int $userId, int $companyId, string $channelKey): bool
    {
        return $this->userEligibleForChannel(User::query()->find($userId), $companyId, $channelKey);
    }

    /**
     * @return array<int>
     */
    public function recipientUserIds(int $companyId, string $channelKey, ?int $excludeUserId): array
    {
        $ids = DB::table('company_user')
            ->where('company_id', $companyId)
            ->pluck('user_id')
            ->map(static fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $out = [];
        foreach ($ids as $userId) {
            if ($excludeUserId !== null && $userId === $excludeUserId) {
                continue;
            }
            if (! $this->userEligibleForChannel(User::query()->find($userId), $companyId, $channelKey)) {
                continue;
            }
            $out[] = $userId;
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function definedChannelKeys(): array
    {
        $channels = config('in_app_notifications.channels', []);
        if (! is_array($channels)) {
            return [];
        }

        return array_map('strval', array_keys($channels));
    }

    private function userMayUseChannel(User $user, int $companyId, string $channelKey): bool
    {
        $channels = config('in_app_notifications.channels', []);
        if (! is_array($channels) || ! isset($channels[$channelKey])) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        $meta = $channels[$channelKey];
        if (! empty($meta['all_company_members'])) {
            return $this->userBelongsToCompany($user, $companyId);
        }

        $requiredAny = $meta['any_permissions'] ?? [];
        if ($requiredAny === [] || ! is_array($requiredAny)) {
            return false;
        }

        $names = $user->getAllPermissionsForCompany($companyId)->pluck('name');
        foreach ($requiredAny as $perm) {
            if (! is_string($perm)) {
                continue;
            }
            if ($names->contains($perm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string>  $userIds
     * @return array<int>
     */
    public function filterEligibleRecipients(int $companyId, string $channelKey, array $userIds, ?int $excludeUserId): array
    {
        $out = [];
        $seen = [];
        foreach ($userIds as $rawId) {
            $userId = (int) $rawId;
            if ($userId < 1 || ($excludeUserId !== null && $userId === $excludeUserId)) {
                continue;
            }
            if (isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;
            if (! DB::table('company_user')->where('company_id', $companyId)->where('user_id', $userId)->exists()) {
                continue;
            }
            if (! $this->userEligibleForChannel(User::query()->find($userId), $companyId, $channelKey)) {
                continue;
            }
            $out[] = $userId;
        }

        return $out;
    }

    private function userBelongsToCompany(User $user, int $companyId): bool
    {
        return DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * @param  User|null  $user
     */
    private function userEligibleForChannel(?User $user, int $companyId, string $channelKey): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }

        if (! $this->userMayUseChannel($user, $companyId, $channelKey)) {
            return false;
        }

        return $this->channelPreferenceIsOn((int) $user->id, $companyId, $channelKey);
    }

    private function channelPreferenceIsOn(int $userId, int $companyId, string $channelKey): bool
    {
        $row = UserNotificationSetting::query()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->first();

        if (! $row || ! is_array($row->channels) || ! array_key_exists($channelKey, $row->channels)) {
            return true;
        }

        return (bool) $row->channels[$channelKey];
    }
}
