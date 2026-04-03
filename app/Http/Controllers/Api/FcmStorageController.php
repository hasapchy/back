<?php

namespace App\Http\Controllers\Api;

use App\Models\UserFcmToken;
use App\Services\PushNotificationSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FcmStorageController extends BaseController
{
    public function __construct(
        private readonly PushNotificationSender $pushNotificationSender,
    ) {
    }

    /**
     * Получить токены текущего пользователя.
     */
    public function show()
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->errorResponse(null, 401);
        }

        $token = $user->fcmToken()->first();

        return $this->successResponse([
            'user_id' => $user->id,
            'web_token' => $token?->web_token,
            'mobile_token' => $token?->mobile_token,
        ]);
    }

    /**
     * Создать или обновить токены текущего пользователя.
     * Если запись уже существует, обновляем ее.
     */
    public function upsert(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->errorResponse(null, 401);
        }

        $validator = Validator::make($request->all(), [
            'web_token' => 'nullable|string|max:5000',
            'mobile_token' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();
        $hasWebToken = array_key_exists('web_token', $validated);
        $hasMobileToken = array_key_exists('mobile_token', $validated);

        if (!$hasWebToken && !$hasMobileToken) {
            return $this->errorResponse('Нужно передать web_token или mobile_token', 422);
        }

        $token = UserFcmToken::query()->firstOrNew([
            'user_id' => $user->id,
        ]);

        if ($hasWebToken) {
            $token->web_token = $validated['web_token'];
        }

        if ($hasMobileToken) {
            $token->mobile_token = $validated['mobile_token'];
        }

        $token->save();

        return $this->successResponse([
            'user_id' => $token->user_id,
            'web_token' => $token->web_token,
            'mobile_token' => $token->mobile_token,
        ], 'FCM токены сохранены');
    }

    /**
     * Удалить токен(ы) текущего пользователя.
     * platform: web|mobile|all (по умолчанию all).
     */
    public function destroy(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->errorResponse(null, 401);
        }

        $validator = Validator::make($request->all(), [
            'platform' => 'nullable|string|in:web,mobile,all',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $platform = $request->input('platform', 'all');
        $token = $user->fcmToken()->first();

        if (!$token) {
            return $this->successResponse(null, 'FCM токены не найдены');
        }

        if ($platform === 'all') {
            $token->delete();

            return $this->successResponse(null, 'FCM токены удалены');
        }

        if ($platform === 'web') {
            $token->web_token = null;
        }

        if ($platform === 'mobile') {
            $token->mobile_token = null;
        }

        if ($token->web_token === null && $token->mobile_token === null) {
            $token->delete();
        } else {
            $token->save();
        }

        return $this->successResponse(null, 'FCM токен удален');
    }

    /**
     * Тест связи Laravel ↔ push-service: фиксированный текст, только проверка доставки.
     */
    public function testSend(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1|max:100',
            'user_ids.*' => 'integer|distinct|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        /** @var array<int, int> $userIds */
        $userIds = array_map('intval', $validator->validated()['user_ids']);

        $result = $this->pushNotificationSender->sendToUserIds(
            $userIds,
            'Hasap — тест push',
            'Проверка связи backend и push-service.',
            [
                'type' => 'connectivity_test',
                'source' => 'laravel',
            ]
        );

        if (($result['error'] ?? null) === 'push_service_not_configured') {
            return $this->errorResponse(
                'Push service не настроен: задайте PUSH_SERVICE_BASE_URL и PUSH_SERVICE_API_KEY в .env',
                503
            );
        }

        return $this->successResponse($result);
    }
}
