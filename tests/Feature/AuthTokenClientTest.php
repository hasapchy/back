<?php

namespace Tests\Feature;

use App\Enums\TokenClient;
use App\Models\Company;
use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTokenClientTest extends TestCase
{

    public function test_mobile_login_revokes_only_mobile_tokens(): void
    {
        $company = Company::factory()->create();
        $password = 'client-type-test-1';
        $user = User::factory()->create([
            'email' => 'mobile-web-split@example.com',
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id);

        $first = $this->postJson('/api/user/login', [
            'email' => 'mobile-web-split@example.com',
            'password' => $password,
        ]);
        $first->assertOk();

        $webIssued = $user->createToken('web-api', ['*']);
        $webIssued->accessToken->forceFill(['client_type' => TokenClient::Web->value])->save();
        $webTokenId = $webIssued->accessToken->id;

        $second = $this->postJson('/api/user/login', [
            'email' => 'mobile-web-split@example.com',
            'password' => $password,
        ]);
        $second->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $webTokenId,
            'client_type' => TokenClient::Web->value,
        ]);

        $this->assertSame(
            2,
            $user->tokensForClient(TokenClient::Mobile)->count(),
            'Mobile login should leave one access + one refresh token.'
        );
    }

    public function test_stateful_web_login_resets_previous_web_sessions(): void
    {
        $company = Company::factory()->create();
        $password = 'client-type-test-3';
        $user = User::factory()->create([
            'email' => 'web-reset-sessions@example.com',
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id);

        $first = $this->statefulWebLogin('web-reset-sessions@example.com', $password);
        $firstSessionId = (int) $first->json('data.auth_session_id');

        $second = $this->statefulWebLogin('web-reset-sessions@example.com', $password);

        $this->assertNull(UserAuthSession::query()->find($firstSessionId));
        $this->assertSame(
            (int) $second->json('data.auth_session_id'),
            UserAuthSession::query()
                ->where('user_id', $user->id)
                ->where('client_type', TokenClient::Web->value)
                ->value('id')
        );
    }

    public function test_stateful_web_login_does_not_revoke_mobile_tokens(): void
    {
        $company = Company::factory()->create();
        $password = 'client-type-test-2';
        $user = User::factory()->create([
            'email' => 'web-preserve-mobile@example.com',
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id);

        $mobile = $this->postJson('/api/user/login', [
            'email' => 'web-preserve-mobile@example.com',
            'password' => $password,
        ]);
        $mobile->assertOk();
        $mobileRefresh = $mobile->json('data.refresh_token');

        $web = $this->statefulWebLogin('web-preserve-mobile@example.com', $password);
        $web->assertJsonMissingPath('data.access_token');

        $this->postJson('/api/user/refresh', [
            'refresh_token' => $mobileRefresh,
        ])->assertOk();
    }

    public function test_refresh_rejects_web_client_tokens(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $webRefresh = $user->createToken('refresh-token', ['refresh']);
        $webRefresh->accessToken->forceFill([
            'client_type' => TokenClient::Web->value,
        ])->save();

        $this->postJson('/api/user/refresh', [
            'refresh_token' => $webRefresh->plainTextToken,
        ])->assertUnauthorized();
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function statefulWebLogin(string $email, string $password)
    {
        config(['sanctum.stateful' => ['localhost', '127.0.0.1', '::1']]);

        return $this
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->withHeader('Origin', 'http://localhost')
            ->withHeader('Referer', 'http://localhost/auth/login')
            ->postJson('/api/user/login', [
                'email' => $email,
                'password' => $password,
            ])
            ->assertOk();
    }
}
