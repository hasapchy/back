<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommentControllerTest extends TestCase
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

    public function test_store_comment_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type', 'id', 'body']);
    }

    public function test_store_comment_success(): void
    {
        $order = Order::factory()->create();

        $data = [
            'type' => 'order',
            'id' => $order->id,
            'body' => 'Test comment',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Комментарий добавлен']);
    }

    public function test_update_comment_requires_validation(): void
    {
        $order = Order::factory()->create();

        $comment = \App\Models\Comment::factory()->create([
            'commentable_type' => Order::class,
            'commentable_id' => $order->id,
            'user_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/comments/{$comment->id}", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['body']);
    }
}

