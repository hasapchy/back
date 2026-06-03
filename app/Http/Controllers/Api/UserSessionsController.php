<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\UserAuthSessionService;
use App\Services\UserCredentialRevocationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * @group Пользователи
 * @subgroup Сессии
 */
class UserSessionsController extends BaseController
{
    public function __construct(
        private readonly UserAuthSessionService $authSessionService,
        private readonly UserCredentialRevocationService $credentialRevocationService,
    ) {
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $this->authSessionService->resolveCurrentSessionId($request, $user);

        return $this->successResponse([
            'sessions' => $this->mapSessions((int) $user->id, $currentId)->values()->all(),
            'current_auth_session_id' => $currentId,
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function indexForUser(Request $request, int $id)
    {
        $target = $this->resolveAdminSessionTarget($request, $id);

        return $this->successResponse([
            'sessions' => $this->mapSessions((int) $target->id, null)->values()->all(),
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyAll(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $this->credentialRevocationService->revokeAll($user, $request, 'sessions_revoked_all');

        return $this->successResponse([
            'credentials_revoked' => true,
        ], 'All sessions revoked');
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyAllForUser(Request $request, int $id)
    {
        $target = $this->resolveAdminSessionTarget($request, $id);
        $this->credentialRevocationService->revokeAll($target, $request, 'sessions_revoked_all');

        return $this->successResponse([
            'credentials_revoked' => true,
        ], 'All sessions revoked');
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();

        $session = UserAuthSession::query()
            ->whereKey($id)
            ->where('user_id', $user->id)
            ->first();

        if ($session === null) {
            return $this->errorResponse(__('api.common.session_not_found'), 404);
        }

        $this->credentialRevocationService->revokeSession($session, $request);

        return $this->successResponse([
            'session_id' => $id,
            'revoked' => true,
        ], 'Session revoked');
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyForUser(Request $request, int $id, int $sessionId)
    {
        $target = $this->resolveAdminSessionTarget($request, $id);

        $session = UserAuthSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $target->id)
            ->first();

        if ($session === null) {
            return $this->errorResponse(__('api.common.session_not_found'), 404);
        }

        $this->credentialRevocationService->revokeSession($session, $request);

        return $this->successResponse([
            'session_id' => $sessionId,
            'revoked' => true,
        ], 'Session revoked');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function mapSessions(int $userId, ?int $currentId): Collection
    {
        return $this->authSessionService->listForUser($userId)->map(
            function (UserAuthSession $session) use ($currentId) {
                return [
                    'id' => $session->id,
                    'client_type' => $session->client_type,
                    'device_name' => $session->device_name,
                    'ip_address' => $session->ip_address,
                    'last_activity_at' => $session->last_activity_at?->toIso8601String(),
                    'created_at' => $session->created_at?->toIso8601String(),
                    'is_current' => $currentId !== null && (int) $session->id === $currentId,
                ];
            }
        );
    }

    /**
     * @return User
     */
    private function resolveAdminSessionTarget(Request $request, int $userId): User
    {
        /** @var User|null $actor */
        $actor = $request->user();
        if ($actor === null || ! $actor->is_admin) {
            throw new AuthorizationException();
        }

        return User::query()->findOrFail($userId);
    }
}
