<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = auth('api')->user();
        $refreshToken = JWTAuth::fromUser($user);

        return response()->json([
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'token_type'   => 'bearer',
            'expires_in'   => auth('api')->factory()->getTTL() * 60, // В секундах
            'refresh_expires_in' => config('jwt.refresh_ttl') * 60, // Время жизни refresh token в секундах
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'permissions' => $user->getPermissionNames()
            ]
        ]);
    }

    public function me()
    {
        $user = auth('api')->user();

        return response()->json([
            'user' => $user,
            'permissions' => $user->getPermissionNames(),
        ]);
    }

    public function logout()
    {
        try {
            // Получаем текущий токен для добавления в черный список
            $token = auth('api')->getToken();
            
            if ($token) {
                // Добавляем токен в черный список
                JWTAuth::invalidate($token);
            }
            
            auth('api')->logout();
            
            return response()->json([
                'message' => 'Successfully logged out',
                'status' => 'success'
            ]);
        } catch (JWTException $e) {
            // Даже если не удалось добавить в черный список, выходим
            auth('api')->logout();
            
            return response()->json([
                'message' => 'Successfully logged out',
                'status' => 'success'
            ]);
        }
    }

    public function refresh(Request $request)
    {
        try {
            $refreshToken = $request->input('refresh_token');

            if (!$refreshToken) {
                return response()->json(['error' => 'Refresh token is required'], 400);
            }

            // Проверяем refresh token
            JWTAuth::setToken($refreshToken);
            $user = JWTAuth::authenticate();

            if (!$user) {
                return response()->json(['error' => 'Invalid refresh token'], 401);
            }

            // Проверяем активность пользователя
            if (!$user->is_active) {
                return response()->json(['error' => 'User account is deactivated'], 403);
            }

            // Генерируем новые токены
            $newAccessToken = auth('api')->login($user);
            $newRefreshToken = JWTAuth::fromUser($user);

            return response()->json([
                'access_token'  => $newAccessToken,
                'refresh_token' => $newRefreshToken,
                'token_type'    => 'bearer',
                'expires_in'    => config('jwt.ttl') * 60, // Время жизни access token в секундах
                'refresh_expires_in' => config('jwt.refresh_ttl') * 60, // Время жизни refresh token в секундах
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => $user->is_admin,
                    'permissions' => $user->getPermissionNames()
                ]
            ]);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Could not refresh token'], 500);
        }
    }
}
