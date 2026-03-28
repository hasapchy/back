<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Repositories\UsersRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends BaseController
{
    /**
     * @var UsersRepository
     */
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param UsersRepository $itemsRepository Репозиторий пользователей
     */
    public function __construct(UsersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Аутентификация пользователя
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            Log::warning('Login attempt: user not found', ['email' => $request->email]);
            return $this->errorResponse('Неверный логин или пароль', 401);
        }

        if (!Hash::check($request->password, $user->password)) {
            Log::warning('Login attempt: invalid password', ['email' => $request->email, 'creator_id' => $user->id]);
            return $this->errorResponse('Неверный логин или пароль', 401);
        }

        if (!$user->is_active) {
            return $this->errorResponse('Account is disabled', 403);
        }

        $user->load('roles', 'permissions');
        $resolvedRoles = $user->getAllRoleNames();

        User::where('id', $user->id)->update(['last_login_at' => now()]);

        RateLimiter::clear('auth:'.$request->ip());

        $remember = $request->boolean('remember');
        $ttl = $remember ? config('sanctum.remember_expiration', 10080) : config('sanctum.expiration', 1440);

        $expiresAt = $ttl ? now()->addMinutes($ttl) : null;

        $fingerprint = $request->input('device_fingerprint');
        if ($fingerprint !== null && $fingerprint !== '') {
            $user->tokens()->where('device_fingerprint', $fingerprint)->delete();
        } else {
            $user->tokens()->delete();
        }

        $accessToken = $user->createToken('access-token', ['*'], $expiresAt);
        $refreshToken = $user->createToken('refresh-token', ['refresh'], $remember ? now()->addMinutes(43200) : now()->addMinutes(10080));

        $deviceName = $request->input('device_name') ?? $request->userAgent();
        $this->setDeviceOnToken($accessToken->accessToken, $fingerprint, $deviceName);
        $this->setDeviceOnToken($refreshToken->accessToken, $fingerprint, $deviceName);

        $companyId = $user->companies()->value('companies.id');
        $permissions = $this->getUserPermissions($user, $companyId ? (int) $companyId : null);

        return $this->successResponse([
            'access_token' => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken->plainTextToken,
            'token_type'   => 'bearer',
            'expires_in'   => $ttl ? $ttl * 60 : null,
            'refresh_expires_in' => ($remember ? 43200 : 10080) * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'surname' => $user->surname,
                'email' => $user->email,
                'photo' => $user->photo,
                'birthday' => $user->birthday?->format('Y-m-d'),
                'is_admin' => $user->is_admin,
                'roles' => $resolvedRoles,
                'permissions' => $permissions
            ]
        ]);
    }

    /**
     * Получить данные текущего аутентифицированного пользователя
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('roles', 'permissions');

        $companyId = $this->getCurrentCompanyId();
        $permissions = $companyId ? $user->getAllPermissionsForCompany((int)$companyId)->pluck('name')->toArray() : $user->getAllPermissions()->pluck('name')->toArray();
        $roles = $user->getAllRoleNames();

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'surname' => $user->surname,
                'email' => $user->email,
                'photo' => $user->photo,
                'birthday' => $user->birthday?->format('Y-m-d'),
                'is_admin' => $user->is_admin,
                'roles' => $roles,
                'permissions' => $permissions
            ],
        ]);
    }

    /**
     * Выход пользователя из системы
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return $this->successResponse(null, 'Successfully logged out');
    }

    /**
     * Обновить access token используя refresh token
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh(Request $request)
    {
        $refreshToken = $request->input('refresh_token');

        if (!$refreshToken) {
            return $this->errorResponse('Refresh token is required', 400);
        }

        $token = PersonalAccessToken::findToken($refreshToken);

        if (!$token) {
            return $this->errorResponse('Invalid refresh token', 401);
        }

        if (!$token->can('refresh')) {
            $token->delete();
            return $this->errorResponse('Invalid refresh token', 401);
        }

        /** @var User $user */
        $user = $token->tokenable;

        if (!$user || !$user->is_active) {
            if ($token) {
                $token->delete();
            }
            return $this->errorResponse('User account is deactivated', 403);
        }

        $user->load('roles', 'permissions');
        $resolvedRoles = $user->getAllRoleNames();

        $isRemember = $token->expires_at && $token->expires_at->gt(now()->addMinutes(43200 - 1440));
        $ttl = $isRemember ? config('sanctum.remember_expiration', 10080) : config('sanctum.expiration', 1440);
        $expiresAt = $ttl ? now()->addMinutes($ttl) : null;

        $token->delete();

        $newAccessToken = $user->createToken('access-token', ['*'], $expiresAt);
        $newRefreshToken = $user->createToken('refresh-token', ['refresh'], $isRemember ? now()->addMinutes(43200) : now()->addMinutes(10080));

        $this->setDeviceOnToken($newAccessToken->accessToken, $token->device_fingerprint, $token->device_name);
        $this->setDeviceOnToken($newRefreshToken->accessToken, $token->device_fingerprint, $token->device_name);

        return $this->successResponse([
            'access_token'  => $newAccessToken->plainTextToken,
            'refresh_token' => $newRefreshToken->plainTextToken,
            'token_type'    => 'bearer',
            'expires_in'    => $ttl ? $ttl * 60 : null,
            'refresh_expires_in' => ($isRemember ? 43200 : 10080) * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'surname' => $user->surname,
                'email' => $user->email,
                'photo' => $user->photo,
                'birthday' => $user->birthday?->format('Y-m-d'),
                'is_admin' => $user->is_admin,
                'roles' => $resolvedRoles,
                'permissions' => $user->getAllPermissions()->pluck('name')->toArray()
            ]
        ]);
    }

    /**
     * @param \Laravel\Sanctum\PersonalAccessToken $token
     * @param string|null $fingerprint
     * @param string|null $deviceName
     * @return void
     */
    private function setDeviceOnToken($token, ?string $fingerprint, ?string $deviceName): void
    {
        $payload = array_filter([
            'device_fingerprint' => $fingerprint,
            'device_name' => $deviceName,
        ], fn ($v) => $v !== null);
        if ($payload !== []) {
            $token->forceFill($payload)->save();
        }
    }
}
