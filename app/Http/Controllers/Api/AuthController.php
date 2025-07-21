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

        return response()->json([
            'access_token' => $token,
            'refresh_token' => JWTAuth::fromUser(auth('api')->user()),
            'token_type'   => 'bearer',
            'expires_in'   => auth('api')->factory()->getTTL() * 1
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
                return response()->json(['error' => 'Invalid refresh token'], 401);
            }

            $newAccessToken = auth('api')->login($user);

            return response()->json([
                'access_token'  => $newAccessToken,
                'refresh_token' => JWTAuth::fromUser($user),
                'token_type'    => 'bearer',
                'expires_in'    => auth('api')->factory()->getTTL() * 60
            ]);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Could not refresh token'], 500);
        }
    }
}
