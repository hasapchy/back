<?php

namespace Tests\Feature;

use App\Enums\TokenClient;
use App\Events\UserCredentialsRevoked;
use App\Models\Company;
use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeRevokesCredentialsTest extends TestCase
{
    public function test_password_change_revokes_tokens_sessions_and_broadcasts(): void
    {
        Event::fake([UserCredentialsRevoked::class]);

        [$company, $user] = $this->createCompanyWithAdminUser();
        $password = 'old-password-1';
        $user->update(['password' => Hash::make($password), 'email' => 'pwd-revoke@example.com']);

        $login = $this->postJson('/api/user/login', [
            'email' => 'pwd-revoke@example.com',
            'password' => $password,
        ]);
        $login->assertOk();
        $accessToken = $login->json('data.access_token');
        $this->assertNotEmpty($accessToken);

        $this->assertGreaterThan(0, UserAuthSession::query()->where('user_id', $user->id)->count());
        $this->assertGreaterThan(0, $user->tokens()->count());

        $this->withApiTokenForCompany($user, $company->id)
            ->postJson('/api/user/profile', [
                'current_password' => $password,
                'password' => 'new-password-2',
            ])
            ->assertOk()
            ->assertJsonPath('data.credentials_revoked', true);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, UserAuthSession::query()->where('user_id', $user->id)->count());

        Event::assertDispatched(UserCredentialsRevoked::class, function (UserCredentialsRevoked $event) use ($user) {
            return $event->userId === $user->id && $event->reason === 'password_changed';
        });

        $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->getJson('/api/user/me')
            ->assertUnauthorized();
    }
}
