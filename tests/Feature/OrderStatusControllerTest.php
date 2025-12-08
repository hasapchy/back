<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\OrderStatus;
use App\Models\OrderStatusCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderStatusControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected OrderStatusCategory $statusCategory;

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
        
        $this->statusCategory = OrderStatusCategory::factory()->create();
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
    }

    public function test_store_order_status_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/order_statuses', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'category_id']);
    }

    public function test_store_order_status_success(): void
    {
        $data = [
            'name' => 'New Status',
            'category_id' => $this->statusCategory->id,
            'is_active' => true,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/order_statuses', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Статус создан']);
    }

    public function test_update_order_status_success(): void
    {
        $status = OrderStatus::factory()->create([
            'category_id' => $this->statusCategory->id,
        ]);

        $data = [
            'name' => 'Updated Status',
            'category_id' => $this->statusCategory->id,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/order_statuses/{$status->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Статус обновлен']);
    }
}

