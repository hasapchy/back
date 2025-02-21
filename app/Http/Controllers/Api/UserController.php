<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class UserController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'access_token' => $token,
            'refresh_token' => JWTAuth::fromUser(auth('api')->user()),
            'token_type'   => 'bearer',
            'expires_in'   => auth('api')->factory()->getTTL() * 1
        ]);
    }

    public function me()
    {
        return response()->json(auth('api')->user());
    }

    public function logout()
    {
        auth('api')->logout();
        return response()->json(['message' => 'Successfully logged out']);
    }

    public function refresh(Request $request)
    {
        try {
            $refreshToken = $request->input('refresh_token');

            if (!$refreshToken) {
                return response()->json(['error' => 'Refresh token is required'], 400);
            }

            JWTAuth::setToken($refreshToken);
            $user = JWTAuth::authenticate();

            if (!$user) {
                return response()->json(['error' => 'Invalid refresh token', 't' => $refreshToken], 401);
            }

            $newAccessToken = auth('api')->login($user);

            return response()->json([
                'access_token'  => $newAccessToken,
                'refresh_token' => JWTAuth::fromUser($user), // Генерируем новый refresh_token
                'token_type'    => 'bearer',
                'expires_in'    => auth('api')->factory()->getTTL() * 60
            ]);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Could not refresh token'], 500);
        }
    }
}
