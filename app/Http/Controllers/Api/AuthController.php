<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repositories\UsersRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    protected $itemsRepository;

    public function __construct(UsersRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    public function login(LoginRequest $request)
    {

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->unauthorizedResponse('Unauthorized');
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

        return response()->json([
            'access_token' => $accessToken->plainTextToken,
            'token_type'   => 'bearer',
            'expires_in'   => $ttl ? $ttl * 60 : null,
            'user' => (new UserResource($user))->resolve(request()),
            'roles' => $resolvedRoles,
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray()
        ]);
    }

    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('roles', 'permissions');

        $companyId = $this->getCurrentCompanyId();
        $permissions = $companyId ? $user->getAllPermissionsForCompany((int)$companyId)->pluck('name')->toArray() : $user->getAllPermissions()->pluck('name')->toArray();
        $roles = $user->getAllRoleNames();

        return response()->json([
            'user' => (new UserResource($user))->resolve(request()),
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Successfully logged out']);
    }

}
