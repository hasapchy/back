<?php

namespace Tests\Feature;

use App\Enums\TokenClient;
use App\Events\UserSessionRevoked;
use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSessionsControllerTest extends TestCase
{
    public function test_lists_sessions_with_current_flag(): void
    {
        [$company, $user] = $this->createCompanyWithAdminUser();
        $password = 'sessions-list-1';
        $user->update(['password' => Hash::make($password), 'email' => 'sessions-list@example.com']);

        $login = $this->postJson('/api/user/login', [
            'email' => 'sessions-list@example.com',
            'password' => $password,
        ]);
        $login->assertOk();
        $authSessionId = $login->json('data.auth_session_id');
        $token = $login->json('data.access_token');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user/sessions');

        $response->assertOk();
        $response->assertJsonPath('data.current_auth_session_id', $authSessionId);
        $sessions = $response->json('data.sessions');
        $this->assertIsArray($sessions);
        $this->assertNotEmpty($sessions);

        $current = collect($sessions)->firstWhere('id', $authSessionId);
        $this->assertNotNull($current);
        $this->assertTrue($current['is_current']);
        $this->assertSame(TokenClient::Mobile->value, $current['client_type']);
    }

    public function test_revoke_single_session(): void
    {
        Event::fake([UserSessionRevoked::class]);

        [$company, $user] = $this->createCompanyWithAdminUser();
        $password = 'sessions-revoke-1';
        $user->update(['password' => Hash::make($password), 'email' => 'sessions-revoke@example.com']);

        $first = $this->postJson('/api/user/login', [
            'email' => 'sessions-revoke@example.com',
            'password' => $password,
        ]);
        $first->assertOk();
        $firstToken = $first->json('data.access_token');
        $firstSessionId = (int) $first->json('data.auth_session_id');

        $second = $this->postJson('/api/user/login', [
            'email' => 'sessions-revoke@example.com',
            'password' => $password,
        ]);
        $second->assertOk();
        $secondSessionId = (int) $second->json('data.auth_session_id');
        $secondToken = $second->json('data.access_token');

        $this->assertNotSame($firstSessionId, $secondSessionId);

        $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->deleteJson('/api/user/sessions/'.$firstSessionId)
            ->assertOk();

        $this->assertNull(UserAuthSession::query()->find($firstSessionId));

        $this->withHeader('Authorization', 'Bearer '.$firstToken)
            ->getJson('/api/user/me')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->getJson('/api/user/me')
            ->assertOk();

        Event::assertDispatched(UserSessionRevoked::class, function (UserSessionRevoked $event) use ($user, $firstSessionId) {
            return $event->userId === $user->id && $event->sessionId === $firstSessionId;
        });
    }

    public function test_cannot_revoke_another_users_session(): void
    {
        [$company, $user] = $this->createCompanyWithAdminUser();
        $other = User::factory()->create(['is_active' => true]);
        $other->companies()->attach($company->id);

        $foreignSession = UserAuthSession::query()->create([
            'user_id' => $other->id,
            'client_type' => TokenClient::Mobile->value,
            'last_activity_at' => now(),
        ]);

        $this->withApiTokenForCompany($user, $company->id)
            ->deleteJson('/api/user/sessions/'.$foreignSession->id)
            ->assertNotFound();
    }

    public function test_admin_lists_employee_sessions(): void
    {
        [$company, $admin] = $this->createCompanyWithAdminUser();
        $employee = User::factory()->create(['is_active' => true, 'is_admin' => false]);
        $employee->companies()->attach($company->id);

        $session = UserAuthSession::query()->create([
            'user_id' => $employee->id,
            'client_type' => TokenClient::Mobile->value,
            'device_name' => 'Employee phone',
            'last_activity_at' => now(),
        ]);

        $response = $this->withApiTokenForCompany($admin, $company->id)
            ->getJson('/api/users/'.$employee->id.'/sessions');

        $response->assertOk();
        $sessions = $response->json('data.sessions');
        $this->assertCount(1, $sessions);
        $this->assertSame($session->id, $sessions[0]['id']);
        $this->assertFalse($sessions[0]['is_current']);
    }

    public function test_non_admin_cannot_list_employee_sessions(): void
    {
        [$company, $admin] = $this->createCompanyWithAdminUser();
        $employee = User::factory()->create(['is_active' => true, 'is_admin' => false]);
        $employee->companies()->attach($company->id);

        $this->withApiTokenForCompany($employee, $company->id)
            ->getJson('/api/users/'.$admin->id.'/sessions')
            ->assertForbidden();
    }

    public function test_admin_revokes_employee_session(): void
    {
        [$company, $admin] = $this->createCompanyWithAdminUser();
        $employee = User::factory()->create(['is_active' => true, 'is_admin' => false]);
        $employee->companies()->attach($company->id);

        $session = UserAuthSession::query()->create([
            'user_id' => $employee->id,
            'client_type' => TokenClient::Mobile->value,
            'last_activity_at' => now(),
        ]);

        $this->withApiTokenForCompany($admin, $company->id)
            ->deleteJson('/api/users/'.$employee->id.'/sessions/'.$session->id)
            ->assertOk();

        $this->assertNull(UserAuthSession::query()->find($session->id));
    }
}
