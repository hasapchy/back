<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Holiday;
use App\Models\User;
use Tests\TestCase;

class HolidaysControllerTest extends TestCase
{

    protected User $adminUser;

    protected Company $company;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
    }

    /**
     * @param  User  $user
     * @return $this
     */
    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    /**
     * @return void
     */
    public function test_store_company_holiday_requires_icon(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/holidays', [
                'company_id' => $this->company->id,
                'name' => 'Test Event',
                'date' => '2026-12-31',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['icon']);
    }

    /**
     * @return void
     */
    public function test_store_company_holiday_rejects_invalid_icon(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/holidays', [
                'company_id' => $this->company->id,
                'name' => 'Test Event',
                'date' => '2026-12-31',
                'icon' => 'fa-solid fa-unknown-icon',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['icon']);
    }

    /**
     * @return void
     */
    public function test_store_company_holiday_persists_icon(): void
    {
        $icon = Holiday::ALLOWED_ICONS[1];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/holidays', [
                'company_id' => $this->company->id,
                'name' => 'Test Event',
                'date' => '2026-12-31',
                'icon' => $icon,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('holidays', [
            'company_id' => $this->company->id,
            'name' => 'Test Event',
            'icon' => $icon,
        ]);
    }

    /**
     * @return void
     */
    public function test_update_company_holiday_persists_icon(): void
    {
        $holiday = Holiday::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Old Event',
            'date' => '2026-01-01',
            'icon' => Holiday::DEFAULT_ICON,
        ]);

        $newIcon = Holiday::ALLOWED_ICONS[2];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/holidays/{$holiday->id}", [
                'name' => 'Updated Event',
                'date' => '2026-01-02',
                'icon' => $newIcon,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('holidays', [
            'id' => $holiday->id,
            'name' => 'Updated Event',
            'icon' => $newIcon,
        ]);
    }
}
