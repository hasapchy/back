<?php

namespace App\Http\Controllers\Api;

use App\Models\Sanctum\PersonalAccessToken;
use App\Models\User;
use App\Services\MobileSanctumTokenService;
use App\Support\AuthRequestLogContext;
use App\Support\ResolvedCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class AuthController extends BaseController
{
    public function __construct(
        private readonly MobileSanctumTokenService $mobileSanctumTokenService
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

            return $this->errorResponse('Неверный логин или пароль', 401);
        }

        if (! $user->is_active) {
            $this->authLoginLog('auth.login.failed', array_merge($baseCtx, [
                'reason' => 'inactive',
                'user_id' => $user->id,
            ]));

            return $this->errorResponse('Account is disabled', 403);
        }

        User::where('id', $user->id)->update(['last_login_at' => now()]);

        $remember = $request->boolean('remember');
        $user->tokens()->delete();

        [$companyId, $error] = $this->resolveLoginCompanyId($request, $user);
        if ($error !== null) {
            $this->authLoginLog('auth.login.failed', array_merge($baseCtx, [
                'reason' => 'company',
                'user_id' => $user->id,
                'company_error' => $error,
            ]));

            return $this->errorResponse($error, 404);
        }

        $stateful = EnsureFrontendRequestsAreStateful::fromFrontend($request);

        if ($stateful) {
            Auth::guard('web')->login($user, $remember);
            $request->session()->regenerate();
            $request->session()->put(ResolvedCompany::SESSION_KEY, $companyId);

            $this->authLoginLog('auth.login.success', array_merge($baseCtx, [
                'user_id' => $user->id,
                'company_id' => $companyId,
                'mode' => 'session',
                'session_id' => $request->session()->getId(),
            ]));

            return $this->successResponse([
                'user' => $this->userPayloadForAuthResponse($user, $companyId),
            ]);
        }

        $this->authLoginLog('auth.login.success', array_merge($baseCtx, [
            'user_id' => $user->id,
            'company_id' => $companyId,
            'mode' => 'token_pair',
        ]));

        return $this->successResponse(array_merge(
            ['user' => $this->userPayloadForAuthResponse($user, $companyId)],
            $this->mobileSanctumTokenService->issueTokenPair($user, $remember, $companyId)
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
            return $this->errorResponse('Refresh token is required', 400);
        }

        $token = PersonalAccessToken::findToken($plain);
        if (! $token || ! $token->can('refresh')) {
            $token?->delete();

            return $this->errorResponse('Invalid refresh token', 401);
        }

        /** @var User $user */
        $user = $token->tokenable;
        if (! $user instanceof User || ! $user->is_active) {
            $token->delete();

            return $this->errorResponse('User account is deactivated', 403);
        }

        $companyId = $token->company_id !== null && $token->company_id !== ''
            ? (int) $token->company_id
            : (int) ($user->companies()->value('companies.id') ?? 0);

        if ($companyId < 1) {
            $token->delete();

            return $this->errorResponse('No companies available', 404);
        }

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            $token->delete();

            return $this->errorResponse('Company not found or access denied', 403);
        }

        $token->delete();

        return $this->successResponse(array_merge(
            ['user' => $this->userPayloadForAuthResponse($user, $companyId)],
            $this->mobileSanctumTokenService->issueTokenPair($user, false, $companyId)
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
        $permissions = $companyId
            ? $user->getAllPermissionsForCompany((int) $companyId)->pluck('name')->toArray()
            : $user->getAllPermissions()->pluck('name')->toArray();

        return $this->successResponse([
            'user' => $this->serializeUserForApi($user, $user->getAllRoleNames(), $permissions),
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user !== null && $user->currentAccessToken() !== null) {
            $user->tokens()->delete();
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->successResponse(null, 'Successfully logged out');
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
            'roles' => $roles,
            'permissions' => $permissions,
        ];
    }
}
