<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Product;
use App\Models\TimelineReadState;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WhPurchase;
use App\Models\CashRegister;
use App\Models\Category;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommentControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;

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

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    /**
     * @return Order
     */
    protected function createScopedOrder(): Order
    {
        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $cash = CashRegister::factory()->create([
            'company_id' => $this->company->id,
        ]);

        return Order::factory()->create([
            'client_id' => $client->id,
            'cash_id' => $cash->id,
            'warehouse_id' => null,
            'project_id' => null,
            'creator_id' => $this->adminUser->id,
        ]);
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
        $order = $this->createScopedOrder();

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
        $order = $this->createScopedOrder();

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
        $order = $this->createScopedOrder();

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=order&id=' . $order->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'items',
                'next_cursor',
                'has_more',
            ],
        ]);
    }

    public function test_timeline_pagination_without_duplicates(): void
    {
        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);

        for ($i = 0; $i < 55; $i++) {
            Comment::factory()->create([
                'commentable_type' => Client::class,
                'commentable_id' => $client->id,
                'creator_id' => $this->adminUser->id,
                'body' => "Comment {$i}",
                'created_at' => now()->subMinutes(55 - $i),
            ]);
        }

        $first = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=client&id='.$client->id.'&limit=50');
        $first->assertStatus(200);
        $first->assertJsonPath('data.has_more', true);
        $firstItems = $first->json('data.items');
        $this->assertCount(50, $firstItems);

        $cursor = $first->json('data.next_cursor');
        $this->assertNotEmpty($cursor);

        $second = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=client&id='.$client->id.'&limit=50&cursor='.$cursor);
        $second->assertStatus(200);
        $secondItems = $second->json('data.items');
        $this->assertGreaterThanOrEqual(5, count($secondItems));

        $firstIds = collect($firstItems)->map(fn (array $row) => $row['type'].'_'.$row['id'])->all();
        $secondIds = collect($secondItems)->map(fn (array $row) => $row['type'].'_'.$row['id'])->all();
        $this->assertEmpty(array_intersect($firstIds, $secondIds));
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

    public function test_timeline_returns_success_for_lead(): void
    {

        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $status = LeadStatus::query()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'name' => 'Новый',
            'color' => '#6c757d',
            'is_active' => true,
            'sort' => 0,
            'kanban_outcome' => null,
        ]);
        $source = LeadSource::query()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'name' => 'Test source',
        ]);
        $leadPayload = [
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $client->id,
            'lead_source_id' => $source->id,
            'status_id' => $status->id,
            'comment' => null,
            'order_id' => null,
        ];
        if (Schema::hasColumn('leads', 'responsible_id')) {
            $leadPayload['responsible_id'] = $this->adminUser->id;
        }
        $lead = Lead::query()->create($leadPayload);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=lead&id=' . $lead->id);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['items', 'next_cursor', 'has_more']]);
    }

    public function test_timeline_returns_success_for_transaction(): void
    {
        $cash = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'balance' => 1000000,
            'is_working_minus' => true,
        ]);
        $transaction = Transaction::factory()->create([
            'cash_id' => $cash->id,
            'creator_id' => $this->adminUser->id,
            'amount' => 100,
            'orig_amount' => 100,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=transaction&id=' . $transaction->id);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['items', 'next_cursor', 'has_more']]);
    }

    public function test_timeline_returns_success_for_product(): void
    {
        $category = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
        $product->categories()->attach($category->id);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=product&id=' . $product->id);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['items', 'next_cursor', 'has_more']]);
    }

    public function test_timeline_returns_success_for_wh_purchase(): void
    {
        $supplier = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $purchase = WhPurchase::query()->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'cash_id' => $cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'draft',
            'date' => now(),
            'amount' => 100,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=wh_purchase&id=' . $purchase->id);

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_timeline_includes_comment_entry_shape(): void
    {
        $order = $this->createScopedOrder();
        Comment::factory()->create([
            'commentable_type' => Order::class,
            'commentable_id' => $order->id,
            'creator_id' => $this->adminUser->id,
            'body' => 'Timeline comment',
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments/timeline?type=order&id=' . $order->id);

        $response->assertStatus(200);
        $rows = $response->json('data.items');
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

        $response->assertStatus(404);
    }

    public function test_unread_counts_excludes_current_user_comments(): void
    {
        $order = $this->createScopedOrder();
        $otherUser = User::factory()->create([
            'is_active' => true,
        ]);
        $otherUser->companies()->attach($this->company->id);

        Comment::factory()->create([
            'commentable_type' => Order::class,
            'commentable_id' => $order->id,
            'creator_id' => $this->adminUser->id,
        ]);
        Comment::factory()->create([
            'commentable_type' => Order::class,
            'commentable_id' => $order->id,
            'creator_id' => $otherUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments/timeline/unread-counts', [
                'type' => 'order',
                'ids' => [$order->id],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath("data.counts.{$order->id}", 1);
    }

    public function test_mark_read_resets_unread_count(): void
    {
        $order = $this->createScopedOrder();
        $otherUser = User::factory()->create([
            'is_active' => true,
        ]);
        $otherUser->companies()->attach($this->company->id);

        Comment::factory()->create([
            'commentable_type' => Order::class,
            'commentable_id' => $order->id,
            'creator_id' => $otherUser->id,
        ]);

        $before = $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments/timeline/unread-counts', [
                'type' => 'order',
                'ids' => [$order->id],
            ]);
        $before->assertStatus(200);
        $before->assertJsonPath("data.counts.{$order->id}", 1);

        $markRead = $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments/timeline/read', [
                'type' => 'order',
                'id' => $order->id,
            ]);

        $markRead->assertStatus(200);

        $this->assertDatabaseHas('timeline_read_states', [
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'commentable_type' => Order::class,
            'commentable_id' => $order->id,
        ]);

        $state = TimelineReadState::query()
            ->where('user_id', $this->adminUser->id)
            ->where('company_id', $this->company->id)
            ->where('commentable_type', Order::class)
            ->where('commentable_id', $order->id)
            ->first();
        $this->assertNotNull($state);

        $after = $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments/timeline/unread-counts', [
                'type' => 'order',
                'ids' => [$order->id],
            ]);
        $after->assertStatus(200);
        $after->assertJsonPath("data.counts.{$order->id}", 0);
    }
}

