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

        $response = $this->actingAs($user, 'web')
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
}
