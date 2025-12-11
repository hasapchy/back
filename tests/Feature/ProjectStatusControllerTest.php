<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\ProjectStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectStatusControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('companies')) {
            $this->markTestSkipped('Таблица companies не существует.');
        }

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
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
        $response->assertJson(['message' => 'Статус создан']);
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
        $response->assertJson(['message' => 'Статус обновлен']);
    }
}

