<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Company;
use App\Models\News;
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
use App\Events\CommentDeleted;
use App\Events\CommentReactionUpdated;
use App\Events\CommentUpdated;
use App\Events\NewsAcknowledgedUpdated;
use App\Events\NewsCreated;
use App\Events\NewsReactionUpdated;
use App\Events\NewsViewedUpdated;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
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

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
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

    /**
     * @return News
     */
    protected function createNewsItem(?int $creatorId = null): News
    {
        return News::query()->create([
            'title' => 'Test news',
            'content' => '<p>Body</p>',
            'company_id' => $this->company->id,
            'creator_id' => $creatorId ?? $this->adminUser->id,
        ]);
    }

    public function test_store_news_comment_with_reply(): void
    {
        $news = $this->createNewsItem();

        $parentResponse = $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments', [
                'type' => 'news',
                'id' => $news->id,
                'body' => 'Top level',
            ]);
        $parentResponse->assertStatus(200);

        $parentId = (int) $parentResponse->json('data.comment.id');
        $this->assertGreaterThan(0, $parentId);

        $replyResponse = $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments', [
                'type' => 'news',
                'id' => $news->id,
                'body' => 'Reply body',
                'parent_id' => $parentId,
            ]);
        $replyResponse->assertStatus(200);
        $replyResponse->assertJsonPath('data.timeline_item.parent_id', $parentId);

        $index = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments?type=news&id='.$news->id);
        $index->assertStatus(200);
        $index->assertJsonPath('data.items.0.replies.0.body', 'Reply body');
    }

    public function test_news_mark_read_and_viewed_by(): void
    {
        $news = $this->createNewsItem();
        $otherUser = User::factory()->create(['is_active' => true]);
        $otherUser->companies()->attach($this->company->id);

        Comment::factory()->create([
            'commentable_type' => News::class,
            'commentable_id' => $news->id,
            'creator_id' => $otherUser->id,
            'body' => 'Hello',
        ]);

        $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments/timeline/read', [
                'type' => 'news',
                'id' => $news->id,
            ])
            ->assertStatus(200);

        $index = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments?type=news&id='.$news->id);
        $index->assertStatus(200);
        $viewedBy = $index->json('data.items.0.viewed_by');
        $this->assertIsArray($viewedBy);
        $this->assertNotEmpty($viewedBy);
    }

    public function test_news_comment_reaction_toggle(): void
    {
        $news = $this->createNewsItem();
        $comment = Comment::factory()->create([
            'commentable_type' => News::class,
            'commentable_id' => $news->id,
            'creator_id' => $this->adminUser->id,
            'body' => 'React me',
        ]);

        $set = $this->actingAsApi($this->adminUser)
            ->postJson("/api/comments/{$comment->id}/reaction", ['emoji' => '👍']);
        $set->assertStatus(200);
        $set->assertJsonPath('data.reactions.0.emoji', '👍');

        $unset = $this->actingAsApi($this->adminUser)
            ->postJson("/api/comments/{$comment->id}/reaction", ['emoji' => '👍']);
        $unset->assertStatus(200);
        $unset->assertJsonPath('data.reactions', []);
    }

    public function test_news_reaction_toggle(): void
    {
        $news = $this->createNewsItem();

        $set = $this->actingAsApi($this->adminUser)
            ->postJson("/api/news/{$news->id}/reaction", ['emoji' => '❤️']);
        $set->assertStatus(200);
        $set->assertJsonPath('data.reactions.0.emoji', '❤️');

        $clear = $this->actingAsApi($this->adminUser)
            ->postJson("/api/news/{$news->id}/reaction", ['emoji' => null]);
        $clear->assertStatus(200);
        $clear->assertJsonPath('data.reactions', []);
    }

    public function test_news_comment_non_owner_update_and_delete_forbidden(): void
    {
        $news = $this->createNewsItem();
        $otherUser = User::factory()->create(['is_active' => true]);
        $otherUser->companies()->attach($this->company->id);

        $comment = Comment::factory()->create([
            'commentable_type' => News::class,
            'commentable_id' => $news->id,
            'creator_id' => $this->adminUser->id,
            'body' => 'Protected',
        ]);

        $this->actingAsApi($otherUser)
            ->putJson("/api/comments/{$comment->id}", ['body' => 'Hacked'])
            ->assertStatus(403);

        $this->actingAsApi($otherUser)
            ->deleteJson("/api/comments/{$comment->id}")
            ->assertStatus(403);
    }

    public function test_news_comment_moderation_delete_with_news_delete_all(): void
    {
        Permission::firstOrCreate(['name' => 'news_delete_all', 'guard_name' => 'api']);

        $moderator = User::factory()->create(['is_active' => true, 'is_admin' => false]);
        $moderator->companies()->attach($this->company->id);
        $moderator->givePermissionTo('news_delete_all');

        $news = $this->createNewsItem();
        $comment = Comment::factory()->create([
            'commentable_type' => News::class,
            'commentable_id' => $news->id,
            'creator_id' => $this->adminUser->id,
            'body' => 'Moderated',
        ]);

        $this->actingAsApi($moderator)
            ->deleteJson("/api/comments/{$comment->id}")
            ->assertStatus(200);
    }

    public function test_news_nested_reply_depth_rejected(): void
    {
        $news = $this->createNewsItem();

        $parentResponse = $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments', [
                'type' => 'news',
                'id' => $news->id,
                'body' => 'Parent',
            ]);
        $parentId = (int) $parentResponse->json('data.comment.id');

        $replyResponse = $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments', [
                'type' => 'news',
                'id' => $news->id,
                'body' => 'Reply',
                'parent_id' => $parentId,
            ]);
        $replyId = (int) $replyResponse->json('data.comment.id');

        $this->actingAsApi($this->adminUser)
            ->postJson('/api/comments', [
                'type' => 'news',
                'id' => $news->id,
                'body' => 'Too deep',
                'parent_id' => $replyId,
            ])
            ->assertStatus(422);
    }

    public function test_news_comments_pagination_cursor(): void
    {
        $news = $this->createNewsItem();

        for ($i = 1; $i <= 25; $i++) {
            Comment::factory()->create([
                'commentable_type' => News::class,
                'commentable_id' => $news->id,
                'creator_id' => $this->adminUser->id,
                'body' => "Comment {$i}",
            ]);
        }

        $firstPage = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments?type=news&id='.$news->id.'&limit=20');
        $firstPage->assertStatus(200);
        $firstPage->assertJsonPath('data.has_more', true);
        $firstPage->assertJsonCount(20, 'data.items');

        $cursor = $firstPage->json('data.next_cursor');
        $this->assertNotNull($cursor);

        $secondPage = $this->actingAsApi($this->adminUser)
            ->getJson('/api/comments?type=news&id='.$news->id.'&limit=20&cursor='.$cursor);
        $secondPage->assertStatus(200);
        $secondPage->assertJsonPath('data.has_more', false);
        $secondPage->assertJsonCount(5, 'data.items');
    }

    public function test_news_reaction_broadcast_events(): void
    {
        Event::fake([NewsReactionUpdated::class, CommentReactionUpdated::class]);

        $news = $this->createNewsItem();
        $comment = Comment::factory()->create([
            'commentable_type' => News::class,
            'commentable_id' => $news->id,
            'creator_id' => $this->adminUser->id,
            'body' => 'Broadcast',
        ]);

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/news/{$news->id}/reaction", ['emoji' => '👍'])
            ->assertStatus(200);

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/comments/{$comment->id}/reaction", ['emoji' => '👍'])
            ->assertStatus(200);

        Event::assertDispatched(NewsReactionUpdated::class, fn (NewsReactionUpdated $event) => $event->newsId === $news->id);
        Event::assertDispatched(CommentReactionUpdated::class, fn (CommentReactionUpdated $event) => $event->commentId === $comment->id);
    }

    public function test_news_engagement_channel_auth(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $news = $this->createNewsItem();

        $this->actingAsApi($this->adminUser)
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => "private-company.{$this->company->id}.news.{$news->id}",
                'socket_id' => '123.456',
            ])
            ->assertOk();
    }

    public function test_news_feed_channel_auth(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->actingAsApi($this->adminUser)
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => "private-company.{$this->company->id}.news.feed",
                'socket_id' => '123.456',
            ])
            ->assertOk();
    }

    public function test_news_comment_updated_broadcast(): void
    {
        Event::fake([CommentUpdated::class]);

        $news = $this->createNewsItem();
        $comment = Comment::factory()->create([
            'commentable_type' => News::class,
            'commentable_id' => $news->id,
            'creator_id' => $this->adminUser->id,
            'body' => 'Original',
        ]);

        $this->actingAsApi($this->adminUser)
            ->putJson("/api/comments/{$comment->id}", ['body' => 'Updated body'])
            ->assertStatus(200);

        Event::assertDispatched(CommentUpdated::class, function (CommentUpdated $event) use ($news, $comment) {
            return $event->companyId === $this->company->id
                && $event->newsId === $news->id
                && $event->commentId === $comment->id
                && $event->body === 'Updated body'
                && $event->parentId === null;
        });
    }

    public function test_news_comment_deleted_broadcast(): void
    {
        Event::fake([CommentDeleted::class]);

        $news = $this->createNewsItem();
        $parent = Comment::factory()->create([
            'commentable_type' => News::class,
            'commentable_id' => $news->id,
            'creator_id' => $this->adminUser->id,
            'body' => 'Parent',
        ]);
        $comment = Comment::factory()->create([
            'commentable_type' => News::class,
            'commentable_id' => $news->id,
            'creator_id' => $this->adminUser->id,
            'body' => 'Reply',
            'parent_id' => $parent->id,
        ]);

        $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/comments/{$comment->id}")
            ->assertStatus(200);

        Event::assertDispatched(CommentDeleted::class, function (CommentDeleted $event) use ($news, $comment, $parent) {
            return $event->companyId === $this->company->id
                && $event->newsId === $news->id
                && $event->commentId === $comment->id
                && $event->parentId === $parent->id;
        });
    }

    public function test_order_comment_update_does_not_broadcast_news_events(): void
    {
        Event::fake([CommentUpdated::class, CommentDeleted::class]);

        $order = $this->createScopedOrder();
        $comment = Comment::factory()->create([
            'commentable_type' => Order::class,
            'commentable_id' => $order->id,
            'creator_id' => $this->adminUser->id,
            'body' => 'Order comment',
        ]);

        $this->actingAsApi($this->adminUser)
            ->putJson("/api/comments/{$comment->id}", ['body' => 'Updated'])
            ->assertStatus(200);

        Event::assertNotDispatched(CommentUpdated::class);
    }

    public function test_news_created_broadcast(): void
    {
        Event::fake([NewsCreated::class]);

        $this->actingAsApi($this->adminUser)
            ->postJson('/api/news', [
                'title' => 'Broadcast news',
                'content' => '<p>New item</p>',
            ])
            ->assertStatus(200);

        Event::assertDispatched(NewsCreated::class, function (NewsCreated $event) {
            return $event->companyId === $this->company->id
                && ($event->news['title'] ?? '') === 'Broadcast news'
                && ($event->news['content'] ?? '') === '<p>New item</p>';
        });
    }

    public function test_news_viewed_broadcast(): void
    {
        Event::fake([NewsViewedUpdated::class]);

        $news = $this->createNewsItem();

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/news/{$news->id}/view")
            ->assertStatus(200);

        Event::assertDispatched(NewsViewedUpdated::class, function (NewsViewedUpdated $event) use ($news) {
            return $event->companyId === $this->company->id
                && $event->newsId === $news->id
                && is_array($event->viewedBy)
                && collect($event->viewedBy)->contains(fn (array $row) => (int) ($row['user_id'] ?? 0) === $this->adminUser->id);
        });
    }

    public function test_news_acknowledged_broadcast(): void
    {
        Event::fake([NewsAcknowledgedUpdated::class]);

        $news = News::query()->create([
            'title' => 'Important',
            'content' => '<p>Must read</p>',
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'is_important' => true,
        ]);

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/news/{$news->id}/acknowledge")
            ->assertStatus(200);

        Event::assertDispatched(NewsAcknowledgedUpdated::class, function (NewsAcknowledgedUpdated $event) use ($news) {
            return $event->companyId === $this->company->id
                && $event->newsId === $news->id
                && is_array($event->acknowledgedBy)
                && collect($event->acknowledgedBy)->contains(fn (array $row) => (int) ($row['user_id'] ?? 0) === $this->adminUser->id);
        });
    }
}

