<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
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

    public function test_me_returns_409_when_pat_has_no_company(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);

        $response = $this->withApiTokenForCompany($user, null)
            ->getJson('/api/user/me');

        $response->assertStatus(409);
        $response->assertJsonPath('error', __('api.common.company_context_missing'));
    }

    public function test_me_returns_user_with_permissions_when_pat_has_company(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);

        $response = $this->withApiTokenForCompany($user, (int) $company->id)
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
