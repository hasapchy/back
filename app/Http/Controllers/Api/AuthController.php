<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Models\User;
use App\Repositories\UsersRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use App\Models\PersonalAccessToken;

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
            return $this->unauthorizedResponse('Неверный логин или пароль');
        }

        if (!Hash::check($request->password, $user->password)) {
            Log::warning('Login attempt: invalid password', ['email' => $request->email, 'user_id' => $user->id]);
            return $this->unauthorizedResponse('Неверный логин или пароль');
        }

        if (!$user->is_active) {
            return $this->forbiddenResponse('Account is disabled');
        }

        $user->load('roles', 'permissions');
        $resolvedRoles = $user->getAllRoleNames();

        User::where('id', $user->id)->update(['last_login_at' => now()]);

        RateLimiter::clear('auth:'.$request->ip());

        $remember = $request->boolean('remember');
        $ttl = $remember ? config('sanctum.remember_expiration', 10080) : config('sanctum.expiration', 1440);

        $expiresAt = $ttl ? now()->addMinutes($ttl) : null;

        $accessToken = $user->createToken('access-token', ['*'], $expiresAt);
        $refreshToken = $user->createToken('refresh-token', ['refresh'], $remember ? now()->addMinutes(43200) : now()->addMinutes(10080));

        return response()->json([
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
                'permissions' => $user->getAllPermissions()->pluck('name')->toArray()
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

        return response()->json([
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
        /** @var \Laravel\Sanctum\PersonalAccessToken|null $token */
        $token = $user?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => 'Successfully logged out']);
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
            return $this->unauthorizedResponse('Invalid refresh token');
        }

        if (!$token->can('refresh')) {
            $token->delete();
            return $this->unauthorizedResponse('Invalid refresh token');
        }

        /** @var User $user */
        $user = $token->tokenable;

        if (!$user || !$user->is_active) {
            if ($token) {
                $token->delete();
            }
            return $this->forbiddenResponse('User account is deactivated');
        }

        $user->load('roles', 'permissions');
        $resolvedRoles = $user->getAllRoleNames();

        $isRemember = $token->expires_at && $token->expires_at->gt(now()->addMinutes(43200 - 1440));
        $ttl = $isRemember ? config('sanctum.remember_expiration', 10080) : config('sanctum.expiration', 1440);
        $expiresAt = $ttl ? now()->addMinutes($ttl) : null;

        $token->delete();

        $newAccessToken = $user->createToken('access-token', ['*'], $expiresAt);
        $newRefreshToken = $user->createToken('refresh-token', ['refresh'], $isRemember ? now()->addMinutes(43200) : now()->addMinutes(10080));

        return response()->json([
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
}
