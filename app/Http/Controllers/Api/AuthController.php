<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\UsersRepository;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    protected $itemsRepository;

    public function __construct(UsersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        if (!$token = auth('api')->attempt($credentials)) {
            return $this->unauthorizedResponse('Unauthorized');
        }

        /** @var User $user */
        $user = auth('api')->user();
        $user->load('roles', 'permissions');

        if (!$user->is_active) {
            auth('api')->logout();
            return $this->forbiddenResponse('Account is disabled');
        }

        User::where('id', $user->id)->update(['last_login_at' => now()]);

        $ttl = $remember ? config('jwt.remember_ttl', 10080) : config('jwt.ttl', 1440);
        $refreshTtl = $remember ? config('jwt.remember_refresh_ttl', 43200) : config('jwt.refresh_ttl', 10080);

        $customToken = JWTAuth::customClaims(['exp' => now()->addMinutes($ttl)->timestamp])->fromUser($user);
        $refreshToken = JWTAuth::customClaims(['exp' => now()->addMinutes($refreshTtl)->timestamp])->fromUser($user);


        return response()->json([
            'access_token' => $customToken,
            'refresh_token' => $refreshToken,
            'token_type'   => 'bearer',
            'expires_in'   => $ttl * 60,
            'refresh_expires_in' => $refreshTtl * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'photo' => $user->photo,
                'is_admin' => $user->is_admin,
                'roles' => $user->roles->pluck('name')->toArray(),
                'permissions' => $user->getAllPermissions()->pluck('name')->toArray()
            ]
        ]);
    }

    public function me()
    {
        /** @var User $user */
        $user = auth('api')->user();
        $user->load('roles', 'permissions');

        $companyId = $this->getCurrentCompanyId();
        $permissions = $companyId ? $user->getAllPermissionsForCompany((int)$companyId)->pluck('name')->toArray() : $user->getAllPermissions()->pluck('name')->toArray();
        $roles = $companyId ? $user->getRolesForCompany((int)$companyId)->pluck('name')->toArray() : $user->roles->pluck('name')->toArray();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'photo' => $user->photo,
                'is_admin' => $user->is_admin,
                'roles' => $roles,
                'permissions' => $permissions
            ],
            'permissions' => $permissions,
        ]);
    }

    public function logout()
    {
        try {
            $token = JWTAuth::getToken();

            if ($token) {
                JWTAuth::invalidate($token);
            }

            auth('api')->logout();

            return response()->json(['message' => 'Successfully logged out']);
        } catch (JWTException $e) {
            auth('api')->logout();

            return response()->json(['message' => 'Successfully logged out']);
        }
    }

    public function refresh(Request $request)
    {
        try {
            $refreshToken = $request->input('refresh_token');

            if (!$refreshToken) {
                return $this->errorResponse('Refresh token is required', 400);
            }

            JWTAuth::setToken($refreshToken);
            /** @var User|null $user */
            $user = JWTAuth::authenticate();

            if (!$user) {
                return $this->unauthorizedResponse('Invalid refresh token');
            }

            if (!$user->is_active) {
                return $this->forbiddenResponse('User account is deactivated');
            }

            $user->load('roles', 'permissions');

            $newAccessToken = auth('api')->login($user);
            $newRefreshToken = JWTAuth::fromUser($user);

            return response()->json([
                'access_token'  => $newAccessToken,
                'refresh_token' => $newRefreshToken,
                'token_type'    => 'bearer',
                'expires_in'    => config('jwt.ttl') * 60,
                'refresh_expires_in' => config('jwt.refresh_ttl') * 60,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => $user->is_admin,
                    'roles' => $user->roles->pluck('name')->toArray(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->toArray()
                ]
            ]);
        } catch (JWTException $e) {
            return $this->errorResponse('Could not refresh token', 500);
        }
    }
}
