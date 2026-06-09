<?php

namespace Tests\Feature;

use App\Enums\TokenClient;
use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AuthLoggingTest extends TestCase
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    private array $authLogEntries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->authLogEntries = [];

        $channel = Mockery::mock();
        $channel->shouldReceive('info')
            ->andReturnUsing(function (string $message, array $context = []) {
                $this->authLogEntries[] = [
                    'level' => 'info',
                    'message' => $message,
                    'context' => $context,
                ];
            });
        $channel->shouldReceive('warning')
            ->andReturnUsing(function (string $message, array $context = []) {
                $this->authLogEntries[] = [
                    'level' => 'warning',
                    'message' => $message,
                    'context' => $context,
                ];
            });

        Log::shouldReceive('channel')->with('auth')->andReturn($channel);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  callable(array{level: string, message: string, context: array<string, mixed>}): bool  $matcher
     */
    private function assertAuthLogged(callable $matcher): void
    {
        foreach ($this->authLogEntries as $entry) {
            if ($matcher($entry)) {
                $this->assertTrue(true);

                return;
            }
        }

        $messages = array_map(
            fn (array $entry) => $entry['level'].':'.$entry['message'],
            $this->authLogEntries
        );
        $this->fail('Expected auth log entry not found. Logged: '.implode(', ', $messages));
    }

    public function test_login_success_logs_to_auth_channel(): void
    {
        $company = $this->createCompanyWithAdminUser()[0];
        $password = 'auth-log-login-1';
        $user = User::factory()->create([
            'email' => 'auth-log-login@example.com',
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id);

        $response = $this->postJson('/api/user/login', [
            'email' => 'auth-log-login@example.com',
            'password' => $password,
            'remember' => true,
        ]);

        $response->assertOk();

        $this->assertAuthLogged(function (array $entry) {
            return $entry['level'] === 'info'
                && $entry['message'] === 'auth.login.success'
                && ($entry['context']['mode'] ?? null) === 'token_pair'
                && ($entry['context']['remember'] ?? null) === true;
        });
    }

    public function test_logout_logs_to_auth_channel(): void
    {
        $company = $this->createCompanyWithAdminUser()[0];
        $password = 'auth-log-logout-1';
        $user = User::factory()->create([
            'email' => 'auth-log-logout@example.com',
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id);

        $login = $this->postJson('/api/user/login', [
            'email' => 'auth-log-logout@example.com',
            'password' => $password,
        ]);
        $login->assertOk();
        $token = $login->json('data.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/logout')
            ->assertOk();

        $this->assertAuthLogged(function (array $entry) {
            return $entry['level'] === 'info'
                && $entry['message'] === 'auth.logout'
                && ($entry['context']['reason'] ?? null) === 'explicit';
        });
    }

    public function test_revoke_session_logs_to_auth_channel(): void
    {
        $company = $this->createCompanyWithAdminUser()[0];
        $password = 'auth-log-revoke-1';
        $user = User::factory()->create([
            'email' => 'auth-log-revoke@example.com',
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id);

        $login = $this->postJson('/api/user/login', [
            'email' => 'auth-log-revoke@example.com',
            'password' => $password,
        ]);
        $login->assertOk();
        $token = $login->json('data.access_token');

        $staleSession = UserAuthSession::query()->create([
            'user_id' => $user->id,
            'client_type' => TokenClient::Mobile->value,
            'device_name' => 'Old device',
            'last_activity_at' => now()->subDay(),
        ]);
        $staleSessionId = (int) $staleSession->id;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/user/sessions/'.$staleSessionId)
            ->assertOk();

        $this->assertAuthLogged(function (array $entry) use ($staleSessionId) {
            return $entry['level'] === 'info'
                && $entry['message'] === 'auth.session.revoked'
                && ($entry['context']['auth_session_id'] ?? null) === $staleSessionId;
        });

        $this->assertNull(UserAuthSession::query()->find($staleSessionId));
    }

    public function test_unauthenticated_logs_to_auth_channel(): void
    {
        $this->getJson('/api/user/me')->assertUnauthorized();

        $this->assertAuthLogged(function (array $entry) {
            return $entry['level'] === 'warning'
                && $entry['message'] === 'auth.unauthenticated'
                && ($entry['context']['status'] ?? null) === 401
                && ($entry['context']['path'] ?? null) === 'api/user/me';
        });
    }

    public function test_credentials_revoked_logs_to_auth_channel(): void
    {
        $company = $this->createCompanyWithAdminUser()[0];
        $password = 'auth-log-creds-1';
        $user = User::factory()->create([
            'email' => 'auth-log-creds@example.com',
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id);

        $login = $this->postJson('/api/user/login', [
            'email' => 'auth-log-creds@example.com',
            'password' => $password,
        ]);
        $login->assertOk();
        $token = $login->json('data.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/user/sessions')
            ->assertOk();

        $this->assertAuthLogged(function (array $entry) use ($user) {
            return $entry['level'] === 'info'
                && $entry['message'] === 'auth.credentials.revoked'
                && ($entry['context']['user_id'] ?? null) === $user->id
                && ($entry['context']['reason'] ?? null) === 'sessions_revoked_all';
        });
    }
}
