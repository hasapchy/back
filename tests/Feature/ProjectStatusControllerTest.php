<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\ProjectStatus;
use Tests\TestCase;

class ProjectStatusControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->adminUser] = $this->createCompanyWithAdminUser();
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    public function test_store_project_status_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/project-statuses', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_project_status_success(): void
    {
        $data = [
            'name' => 'New Status',
            'color' => '#FF0000',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/project-statuses', $data);

        $response->assertStatus(200);
        $response->assertJsonPath('message', __('api.statuses.created'));
        $this->assertDatabaseHas('project_statuses', [
            'name' => 'New Status',
            'color' => '#FF0000',
        ]);
    }

    public function test_update_project_status_success(): void
    {
        $status = ProjectStatus::factory()->create();

        $data = [
            'name' => 'Updated Status',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/project-statuses/{$status->id}", $data);

        $response->assertStatus(200);
        $response->assertJsonPath('message', __('api.statuses.updated'));
        $this->assertDatabaseHas('project_statuses', [
            'id' => $status->id,
            'name' => 'Updated Status',
        ]);
    }

    public function test_destroy_project_status_success(): void
    {
        $status = ProjectStatus::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/project-statuses/{$status->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', $response->json('message'));
        $this->assertDatabaseMissing('project_statuses', ['id' => $status->id]);
    }

    public function test_non_admin_cannot_store_project_status(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->postJson('/api/project-statuses', [
            'name' => 'No Access',
            'color' => '#000000',
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_project_status(): void
    {
        $status = ProjectStatus::factory()->create(['creator_id' => $this->adminUser->id]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->putJson("/api/project-statuses/{$status->id}", ['name' => 'No Access']);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_destroy_project_status(): void
    {
        $status = ProjectStatus::factory()->create(['creator_id' => $this->adminUser->id]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->deleteJson("/api/project-statuses/{$status->id}");

        $response->assertStatus(403);
    }
}

