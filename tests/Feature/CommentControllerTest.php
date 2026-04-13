<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Company;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Transaction;
use App\Models\User;
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
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
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
        $response->assertJsonPath('data.message', 'Комментарий добавлен');
    }

    public function test_update_comment_requires_validation(): void
    {
        $order = Order::factory()->create();

        $comment = \App\Models\Comment::factory()->create([
            'commentable_type' => Order::class,
            'commentable_id' => $order->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/comments/{$comment->id}", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['body']);
    }

    public function test_timeline_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type', 'id']);
    }

    public function test_timeline_returns_success_for_order(): void
    {
        $order = Order::factory()->create();

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=order&id=' . $order->id);

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_timeline_returns_success_for_client(): void
    {
        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=client&id=' . $client->id);

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_timeline_returns_success_for_project(): void
    {
        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=project&id=' . $project->id);

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_timeline_returns_success_for_project_contract(): void
    {
        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $contract = ProjectContract::factory()->create([
            'project_id' => $project->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=project_contract&id=' . $contract->id);

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_timeline_returns_success_for_transaction(): void
    {
        $transaction = Transaction::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=transaction&id=' . $transaction->id);

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_timeline_includes_comment_entry_shape(): void
    {
        $order = Order::factory()->create();
        Comment::factory()->create([
            'commentable_type' => Order::class,
            'commentable_id' => $order->id,
            'creator_id' => $this->adminUser->id,
            'body' => 'Timeline comment',
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=order&id=' . $order->id);

        $response->assertStatus(200);
        $rows = $response->json();
        $this->assertNotEmpty($rows);
        $commentRow = collect($rows)->firstWhere('type', 'comment');
        $this->assertNotNull($commentRow);
        $this->assertArrayHasKey('id', $commentRow);
        $this->assertArrayHasKey('body', $commentRow);
        $this->assertArrayHasKey('user', $commentRow);
        $this->assertArrayHasKey('created_at', $commentRow);
    }

    public function test_timeline_unknown_type_returns_error(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=unknown_entity&id=1');

        $response->assertStatus(500);
    }
}

