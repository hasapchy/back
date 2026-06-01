<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\ResolvedCompany;
use Tests\TestCase;

class CompanyContextResolutionTest extends TestCase
{

    public function test_current_company_resolves_from_pat_company_id(): void
    {

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

    public function test_me_returns_409_when_company_context_missing_for_stateful_user(): void
    {

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
