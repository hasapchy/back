<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenClient;
use App\Models\Sanctum\PersonalAccessToken;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\MobileSanctumTokenService;
use App\Services\UserAuthSessionService;
use App\Services\UserCredentialRevocationService;
use App\Support\AuthRequestLogContext;
use App\Support\ResolvedCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * @group Авторизация
 * @subgroup Сессия
 */
class AuthController extends BaseController
{
    public function __construct(
        private readonly MobileSanctumTokenService $mobileSanctumTokenService,
        private readonly UserAuthSessionService $authSessionService,
        private readonly UserCredentialRevocationService $credentialRevocationService,
    ) {
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'company_id' => 'nullable|integer',
        ]);

        $baseCtx = array_merge(AuthRequestLogContext::fromRequest($request), [
            'email' => $request->input('email'),
            'remember' => $request->boolean('remember'),
            'company_id_param' => $request->input('company_id'),
        ]);
        $this->authLoginLog('auth.login.attempt', $baseCtx);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            $this->authLoginLog('auth.login.failed', array_merge($baseCtx, [
                'reason' => 'invalid_credentials',
                'user_found' => (bool) $user,
            ]));

            return $this->errorResponse(__('api.auth.invalid_credentials'), 401);
        }

        if (! $user->is_active) {
            $this->authLoginLog('auth.login.failed', array_merge($baseCtx, [
                'reason' => 'inactive',
                'user_id' => $user->id,
            ]));

            return $this->errorResponse(__('api.common.account_disabled'), 403);
        }

        User::where('id', $user->id)->update(['last_login_at' => now()]);

        $remember = $request->boolean('remember');
        $stateful = EnsureFrontendRequestsAreStateful::fromFrontend($request);

        [$companyId, $error] = $this->resolveLoginCompanyId($request, $user);
        if ($error !== null) {
            $this->authLoginLog('auth.login.failed', array_merge($baseCtx, [
                'reason' => 'company',
                'user_id' => $user->id,
                'company_error' => $error,
            ]));

            return $this->errorResponse($error, 404);
        }

        if ($stateful) {
            $user->deleteTokensForClient(TokenClient::Web);

            Auth::guard('web')->login($user, $remember);
            $request->session()->regenerate();
            $request->session()->put(ResolvedCompany::SESSION_KEY, $companyId);

            $authSession = $this->authSessionService->createForLogin($user, $request, TokenClient::Web);
            $request->session()->put('auth_session_id', $authSession->id);

            $this->authLoginLog('auth.login.success', array_merge(
                AuthRequestLogContext::fromRequest($request),
                $baseCtx,
                [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                    'mode' => 'session',
                    'auth_session_id' => $authSession->id,
                    'remember_cookie_will_be_set' => $remember,
                ]
            ));

            return $this->successResponse([
                'user' => $this->userPayloadForAuthResponse($user, $companyId),
                'auth_session_id' => $authSession->id,
            ]);
        }

        $this->authSessionService->resetMobileAuth($user);

        $this->authLoginLog('auth.login.success', array_merge($baseCtx, [
            'user_id' => $user->id,
            'company_id' => $companyId,
            'mode' => 'token_pair',
        ]));

        return $this->successResponse(array_merge(
            ['user' => $this->userPayloadForAuthResponse($user, $companyId)],
            $this->mobileSanctumTokenService->issueTokenPair($user, $remember, $companyId, $request)
        ));
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh(Request $request)
    {
        $plain = $request->input('refresh_token');
        if (! is_string($plain) || $plain === '') {
            return $this->errorResponse(__('api.common.refresh_token_required'), 400);
        }

        $token = PersonalAccessToken::findToken($plain);
        if (! $token || ! $token->can('refresh') || ! $token->isMobile()) {
            $token?->delete();

            return $this->errorResponse(__('api.common.refresh_token_invalid'), 401);
        }

        /** @var User $user */
        $user = $token->tokenable;
        if (! $user instanceof User || ! $user->is_active) {
            $token->delete();

            return $this->errorResponse(__('api.common.user_account_deactivated'), 403);
        }

        $companyId = $token->company_id !== null && $token->company_id !== ''
            ? (int) $token->company_id
            : (int) ($user->companies()->value('companies.id') ?? 0);

        if ($companyId < 1) {
            $token->delete();

            return $this->errorResponse(__('api.common.no_companies_available'), 404);
        }

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            $token->delete();

            return $this->errorResponse(__('api.common.company_not_found_or_access_denied'), 403);
        }

        $authSession = $token->auth_session_id
            ? UserAuthSession::query()->find($token->auth_session_id)
            : null;

        if ($authSession !== null) {
            $authSession->tokens()->delete();
        } else {
            $token->delete();
        }

        return $this->successResponse(array_merge(
            ['user' => $this->userPayloadForAuthResponse($user, $companyId)],
            $this->mobileSanctumTokenService->issueTokenPair($user, false, $companyId, $request, $authSession)
        ));
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('roles', 'permissions');

        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            AuthRequestLogContext::logAuth('warning', 'auth.me.failed', array_merge(
                AuthRequestLogContext::fromRequest($request),
                AuthRequestLogContext::forUser($user),
                ['reason' => 'company_context_missing']
            ));

            return $this->errorResponse(__('api.common.company_context_missing'), 409);
        }

        $permissions = $user->getAllPermissionsForCompany((int) $companyId)->pluck('name')->toArray();
        $currentAuthSessionId = $this->authSessionService->resolveCurrentSessionId($request, $user);

        if (AuthRequestLogContext::verbose()) {
            AuthRequestLogContext::logAuth('info', 'auth.me.success', array_merge(
                AuthRequestLogContext::fromRequest($request),
                AuthRequestLogContext::forUser($user),
                [
                    'auth_session_id' => $currentAuthSessionId,
                    'company_id' => $companyId,
                ]
            ));
        }

        return $this->successResponse([
            'user' => $this->serializeUserForApi($user, $user->getAllRoleNames(), $permissions),
            'current_auth_session_id' => $currentAuthSessionId,
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user !== null) {
            $authSessionId = $this->authSessionService->resolveCurrentSessionId($request, $user);
            $authSession = $authSessionId !== null
                ? UserAuthSession::query()
                    ->whereKey($authSessionId)
                    ->where('user_id', $user->id)
                    ->first()
                : null;

            if ($authSession !== null) {
                AuthRequestLogContext::logAuth('info', 'auth.logout', array_merge(
                    AuthRequestLogContext::fromRequest($request),
                    AuthRequestLogContext::forUser($user),
                    [
                        'auth_session_id' => $authSessionId,
                        'client_type' => $authSession->client_type,
                        'reason' => 'explicit',
                    ]
                ));
                $this->credentialRevocationService->revokeSession($authSession, $request);

                return $this->successResponse(null, __('api.common.logged_out_success'));
            }

            AuthRequestLogContext::logAuth('info', 'auth.logout', array_merge(
                AuthRequestLogContext::fromRequest($request),
                AuthRequestLogContext::forUser($user),
                [
                    'auth_session_id' => $authSessionId,
                    'client_type' => 'token',
                    'reason' => 'explicit',
                ]
            ));
            $user->tokens()->delete();
            $this->authSessionService->invalidateWebSession($request);
        }

        return $this->successResponse(null, __('api.common.logged_out_success'));
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function resolveLoginCompanyId(Request $request, User $user): array
    {
        if ($request->filled('company_id')) {
            $cid = (int) $request->input('company_id');
            if ($cid < 1) {
                return [null, 'Company not found or access denied'];
            }
            if (! $user->companies()->where('companies.id', $cid)->exists()) {
                return [null, 'Company not found or access denied'];
            }

            return [$cid, null];
        }

        $firstId = $user->companies()->value('companies.id');

        if ($firstId === null) {
            return [null, 'No companies available'];
        }

        return [(int) $firstId, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayloadForAuthResponse(User $user, ?int $companyId = null): array
    {
        $user->loadMissing('roles', 'permissions');
        if ($companyId === null) {
            $companyId = $user->companies()->value('companies.id');
        }
        $permissions = $companyId
            ? $this->getUserPermissions($user, (int) $companyId)
            : $user->getAllPermissions()->pluck('name')->toArray();

        return $this->serializeUserForApi($user, $user->getAllRoleNames(), $permissions);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function authLoginLog(string $message, array $context): void
    {
        AuthRequestLogContext::logAuth('info', $message, $context);
    }

    /**
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $permissions
     * @return array<string, mixed>
     */
    private function serializeUserForApi(User $user, array $roles, array $permissions): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'surname' => $user->surname,
            'email' => $user->email,
            'photo' => $user->photo,
            'birthday' => $user->birthday?->format('Y-m-d'),
            'is_admin' => $user->is_admin,
            'is_simple_user' => (bool) $user->is_simple_user,
            'simple_category_id' => $user->simple_category_id,
            'simple_warehouse_id' => $user->simple_warehouse_id,
            'roles' => $roles,
            'permissions' => $permissions,
        ];
    }
}
