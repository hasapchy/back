<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('users') || ! Schema::hasTable('companies')) {
            $this->markTestSkipped('Нужны таблицы users и companies. Выполните миграции.');
        }
    }

    public function test_login_succeeds_with_valid_credentials_and_returns_user_payload(): void
    {
        $company = Company::factory()->create();
        $plainPassword = 'secret-login-42';
        $user = User::factory()->create([
            'email' => 'auth-test@example.com',
            'password' => Hash::make($plainPassword),
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id);

        $response = $this->postJson('/api/user/login', [
            'email' => 'auth-test@example.com',
            'password' => $plainPassword,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'user' => ['id', 'email', 'name', 'permissions'],
            ],
        ]);
        $response->assertJsonPath('data.user.email', 'auth-test@example.com');

        $data = $response->json('data');
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertNotEmpty($data['access_token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create([
            'email' => 'wrong-pass@example.com',
            'password' => Hash::make('correct-password'),
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id);

        $response = $this->postJson('/api/user/login', [
            'email' => 'wrong-pass@example.com',
            'password' => 'other-password',
        ]);

        $response->assertUnauthorized();
        $response->assertJsonPath('error', 'Неверный логин или пароль');
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $response = $this->postJson('/api/user/login', [
            'email' => 'nobody@example.com',
            'password' => 'any-password',
        ]);

        $response->assertUnauthorized();
        $response->assertJsonPath('error', 'Неверный логин или пароль');
    }

    public function test_login_fails_when_user_is_inactive(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('password'),
            'is_active' => false,
        ]);
        $user->companies()->attach($company->id);

        $response = $this->postJson('/api/user/login', [
            'email' => 'inactive@example.com',
            'password' => 'password',
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('error', 'Account is disabled');
    }

    public function test_login_fails_when_user_has_no_companies(): void
    {
        $user = User::factory()->create([
            'email' => 'no-company@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/user/login', [
            'email' => 'no-company@example.com',
            'password' => 'password',
        ]);

        $response->assertNotFound();
        $response->assertJsonPath('error', 'No companies available');
    }

    public function test_login_with_invalid_company_id_returns_not_found(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create([
            'email' => 'bad-company@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id);

        $response = $this->postJson('/api/user/login', [
            'email' => 'bad-company@example.com',
            'password' => 'password',
            'company_id' => 999999,
        ]);

        $response->assertNotFound();
        $response->assertJsonPath('error', 'Company not found or access denied');
    }

    public function test_login_validates_required_fields(): void
    {
        $response = $this->postJson('/api/user/login', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email', 'password']);
    }
}
