<?php

namespace App\Services\InAppNotifications;

use App\Events\AppNotificationCreated;
use App\Models\AppNotification;
use App\Services\PushNotificationSender;
use Illuminate\Support\Facades\Log;

class InAppNotificationDispatcher
{
    public function __construct(
        private readonly UserNotificationSettingsService $settingsService,
        private readonly PushNotificationSender $pushNotificationSender,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function dispatch(
        int $companyId,
        string $channelKey,
        ?int $excludeUserId,
        string $title,
        ?string $body,
        array $data = [],
    ): void {
        if (! $this->channelConfigured($channelKey)) {
            return;
        }

        $recipientIds = $this->settingsService->recipientUserIds($companyId, $channelKey, $excludeUserId);
        $this->deliver($companyId, $channelKey, $recipientIds, $title, $body, $data, true);
    }

    /**
     * @param  array<int|string>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function dispatchToUserIds(
        int $companyId,
        string $channelKey,
        array $userIds,
        ?int $excludeUserId,
        string $title,
        ?string $body,
        array $data = [],
        bool $mirrorFcm = true,
    ): void {
        if (! $this->channelConfigured($channelKey)) {
            return;
        }

        $recipientIds = $this->settingsService->filterEligibleRecipients($companyId, $channelKey, $userIds, $excludeUserId);
        $this->deliver($companyId, $channelKey, $recipientIds, $title, $body, $data, $mirrorFcm);
    }

    /**
     * @param  array<int>  $recipientIds
     * @param  array<string, mixed>  $data
     */
    private function deliver(
        int $companyId,
        string $channelKey,
        array $recipientIds,
        string $title,
        ?string $body,
        array $data,
        bool $mirrorFcm,
    ): void {
        if ($recipientIds === []) {
            return;
        }

        foreach ($recipientIds as $userId) {
            try {
                $notification = AppNotification::query()->create([
                    'user_id' => $userId,
                    'company_id' => $companyId,
                    'channel_key' => $channelKey,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data !== [] ? $data : null,
                ]);

                event(new AppNotificationCreated(
                    $companyId,
                    $userId,
                    [
                        'id' => $notification->id,
                        'channel_key' => $notification->channel_key,
                        'title' => $notification->title,
                        'body' => $notification->body,
                        'data' => $notification->data ?? [],
                        'read_at' => null,
                        'created_at' => $notification->created_at?->toIso8601String(),
                    ]
                ));

                if ($mirrorFcm && config('in_app_notifications.fcm_mirror', false)) {
                    $pushData = array_merge(
                        [
                            'channel_key' => $channelKey,
                            'notification_id' => (string) $notification->id,
                        ],
                        $data !== [] ? ['payload' => json_encode($data, JSON_UNESCAPED_UNICODE)] : []
                    );
                    $this->pushNotificationSender->sendToUserIds(
                        [$userId],
                        $title,
                        (string) ($body ?? ''),
                        $pushData
                    );
                }
            } catch (\Throwable $e) {
                Log::error('in_app_notification_dispatch_failed', [
                    'user_id' => $userId,
                    'company_id' => $companyId,
                    'channel' => $channelKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function channelConfigured(string $channelKey): bool
    {
        $channels = config('in_app_notifications.channels', []);

        return is_array($channels) && isset($channels[$channelKey]);
    }
}
