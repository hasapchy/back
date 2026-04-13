<?php

namespace App\Services;

use App\Models\UserFcmToken;
use Illuminate\Support\Facades\Http;

/**
 * Отправка FCM через локальный Node push-service (service/src).
 * Самодостаточный класс: вызывайте из любого места приложения через контейнер или app().
 */
class PushNotificationSender
{
    /**
     * Собрать уникальные непустые токены (web + mobile) для списка пользователей.
     *
     * @param  array<int>  $userIds
     * @return array<int, string>
     */
    public function collectTokensForUserIds(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($id) => (int) $id, $userIds),
            static fn(int $id) => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $rows = UserFcmToken::query()
            ->whereIn('user_id', $ids)
            ->get(['web_token', 'mobile_token']);

        $tokens = [];
        foreach ($rows as $row) {
            foreach (['web_token', 'mobile_token'] as $field) {
                $t = $row->{$field};
                if (is_string($t) && $t !== '') {
                    $tokens[] = $t;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array<int>  $userIds
     * @param  array<string, mixed>  $data  Значения приводятся к строкам для FCM data.
     * @return array{
     *   ok: bool,
     *   tokens_count: int,
     *   push_service?: array<string, mixed>|null,
     *   error?: string,
     *   http_status?: int
     * }
     */
    public function sendToUserIds(array $userIds, string $title, string $body, array $data = []): array
    {
        $tokens = $this->collectTokensForUserIds($userIds);

        if ($tokens === []) {
            return [
                'ok' => false,
                'tokens_count' => 0,
                'error' => 'no_fcm_tokens_for_users',
            ];
        }

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $data
     * @return array{
     *   ok: bool,
     *   tokens_count: int,
     *   push_service?: array<string, mixed>|null,
     *   error?: string,
     *   http_status?: int
     * }
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter(
            $tokens,
            static fn($t) => is_string($t) && $t !== ''
        )));

        if ($tokens === []) {
            return [
                'ok' => false,
                'tokens_count' => 0,
                'error' => 'no_tokens',
            ];
        }

        $baseUrl = rtrim((string) config('push_service.base_url'), '/');
        $apiKey = (string) config('push_service.api_key');
        $timeout = (int) config('push_service.timeout', 15);

        if ($baseUrl === '' || $apiKey === '') {
            return [
                'ok' => false,
                'tokens_count' => count($tokens),
                'error' => 'push_service_not_configured',
            ];
        }

        $url = $baseUrl . '/v1/push/send';
        $payload = [
            'tokens' => $tokens,
            'title' => $title,
            'body' => $body,
        ];
        $normalizedData = $this->normalizeDataPayload($data);
        if ($normalizedData !== []) {
            $payload['data'] = $normalizedData;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->post($url, $payload);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'tokens_count' => count($tokens),
                'error' => $e->getMessage(),
            ];
        }

        $json = $response->json();

        if (! $response->successful()) {
            return [
                'ok' => false,
                'tokens_count' => count($tokens),
                'error' => is_string($response->body()) ? $response->body() : 'push_service_error',
                'http_status' => $response->status(),
                'push_service' => is_array($json) ? $json : null,
            ];
        }

        return [
            'ok' => true,
            'tokens_count' => count($tokens),
            'push_service' => is_array($json) ? $json : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function normalizeDataPayload(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $k = (string) $key;
            if ($k === '') {
                continue;
            }
            $out[$k] = is_string($value)
                ? $value
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $out;
    }
}
