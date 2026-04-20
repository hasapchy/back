<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\ResolvedCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyContextResolutionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_current_company_resolves_from_pat_company_id(): void
    {
        if (! Schema::hasTable('companies')) {
            $this->markTestSkipped('companies table missing');
        }

        $company = Company::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);

        $response = $this->withApiTokenForCompany($user, (int) $company->id)
            ->getJson('/api/user/current-company');

        $response->assertOk();
        $response->assertJsonPath('data.id', $company->id);
    }

    public function test_current_company_resolves_from_session_when_stateful(): void
    {
        if (! Schema::hasTable('companies')) {
            $this->markTestSkipped('companies table missing');
        }

        $company = Company::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);

        $response = $this->withHeader('Origin', 'http://localhost')
            ->withHeader('Referer', 'http://localhost')
            ->actingAs($user, 'web')
            ->withSession([ResolvedCompany::SESSION_KEY => $company->id])
            ->getJson('/api/user/current-company');

        $response->assertOk();
        $response->assertJsonPath('data.id', $company->id);
    }

    public function test_company_header_mismatch_with_pat_returns_409(): void
    {
        if (! Schema::hasTable('companies')) {
            $this->markTestSkipped('companies table missing');
        }

        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach([$company->id, $other->id]);

        $response = $this->withApiTokenForCompany($user, (int) $company->id)
            ->withHeader('X-Company-ID', (string) $other->id)
            ->getJson('/api/user/current-company');

        $response->assertStatus(409);
    }

    public function test_me_returns_409_when_company_context_missing_for_stateful_user(): void
    {
        if (! Schema::hasTable('companies')) {
            $this->markTestSkipped('companies table missing');
        }

        $company = Company::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/user/me');

        $response->assertStatus(409);
        $response->assertJsonPath('error', 'Company context missing');
    }

    public function test_me_returns_user_with_permissions_when_company_context_present_in_session(): void
    {
        if (! Schema::hasTable('companies')) {
            $this->markTestSkipped('companies table missing');
        }

        $company = Company::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);

        $response = $this->withHeader('Origin', 'http://localhost')
            ->withHeader('Referer', 'http://localhost')
            ->actingAs($user, 'web')
            ->withSession([ResolvedCompany::SESSION_KEY => $company->id])
            ->getJson('/api/user/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.user.email', $user->email);
        $response->assertJsonStructure([
            'data' => [
                'user' => ['id', 'email', 'permissions'],
            ],
        ]);
    }
}
