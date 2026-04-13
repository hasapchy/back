<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AppNotificationResource;
use App\Models\AppNotification;
use App\Services\InAppNotifications\UserNotificationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class InAppNotificationController extends BaseController
{
    public function __construct(
        private readonly UserNotificationSettingsService $notificationSettingsService,
    ) {
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function settings(): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse('Company context is required', 422);
        }

        return $this->successResponse([
            'channels' => $this->notificationSettingsService->mergedForUser($user, $companyId),
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse('Company context is required', 422);
        }

        $allowed = $this->notificationSettingsService->mergedForUser($user, $companyId);
        $allowedKeys = array_column($allowed, 'key');

        $validated = $request->validate([
            'channels' => ['required', 'array'],
            'channels.*' => ['boolean'],
        ]);

        $patch = [];
        foreach ($validated['channels'] as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                continue;
            }
            $patch[$key] = (bool) $value;
        }

        $this->notificationSettingsService->updateChannels($user, $companyId, $patch);

        return $this->successResponse([
            'channels' => $this->notificationSettingsService->mergedForUser($user, $companyId),
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse('Company context is required', 422);
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $base = AppNotification::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId);

        $unreadTotal = (clone $base)->whereNull('read_at')->count();

        $paginator = (clone $base)
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->successResponse([
            'items' => AppNotificationResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'unread_total' => $unreadTotal,
            ],
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function markRead(int $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse('Company context is required', 422);
        }

        $row = AppNotification::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->first();

        if (! $row) {
            return $this->errorResponse('Not found', 404);
        }

        if ($row->read_at === null) {
            $row->read_at = now();
            $row->save();
        }

        return $this->successResponse([
            'notification' => (new AppNotificationResource($row))->resolve(),
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAllRead(): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse('Company context is required', 422);
        }

        AppNotification::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->successResponse(['ok' => true]);
    }
}
