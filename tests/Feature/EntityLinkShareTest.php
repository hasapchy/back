<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Tests\Support\Concerns\GrantsChatPermissions;
use Tests\TestCase;

class EntityLinkShareTest extends TestCase
{
    use GrantsChatPermissions;

    protected User $adminUser;

    protected User $outsiderUser;

    protected Company $company;

    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->outsiderUser = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->adminUser->companies()->attach($this->company->id);
        $this->outsiderUser->companies()->attach($this->company->id);

        $this->currency = $this->ensureDefaultCurrencyForCompany($this->company);
    }

    /**
     * @return array{transaction: Transaction, chat: Chat}
     */
    protected function createTransactionWithChat(?CashRegister $cashRegister = null): array
    {
        $cashRegister ??= CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
        ]);
        $category = TransactionCategory::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => 1,
        ]);

        $transaction = Transaction::factory()->create([
            'creator_id' => $this->adminUser->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $cashRegister->id,
            'category_id' => $category->id,
            'type' => 1,
            'orig_amount' => 1500,
            'amount' => 1500,
            'exchange_rate' => 1,
            'note' => 'Test payment',
            'is_debt' => false,
            'client_id' => null,
            'project_id' => null,
            'source_type' => null,
            'source_id' => null,
        ]);

        $chatResponse = $this->actingAsApi($this->adminUser)
            ->postJson('/api/chats/general');
        $chatResponse->assertSuccessful();
        $chat = Chat::query()->findOrFail((int) $chatResponse->json('data.id'));

        return ['transaction' => $transaction, 'chat' => $chat];
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    protected function transactionShareBody(Transaction $transaction, string $comment = ''): string
    {
        $url = '/transactions/'.$transaction->id;
        $comment = trim($comment);

        return $comment !== '' ? $comment.' '.$url : $url;
    }

    protected function expectedTransactionSubtitle(): string
    {
        return 'Приход · '.number_format(1500, 2, '.', ' ').' '.$this->currency->code.' · Test payment';
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>|null
     */
    protected function findEntityLinkMessage(array $messages, int $transactionId): ?array
    {
        foreach ($messages as $message) {
            if ((int) ($message['metadata']['entity_id'] ?? 0) === $transactionId) {
                return $message;
            }
        }

        return null;
    }

    public function test_entity_link_preview_returns_not_found_for_missing_transaction(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/entity-links/preview?entity=transaction&entity_id=999999999');

        $response->assertStatus(404);
    }

    public function test_entity_link_preview_returns_metadata(): void
    {
        ['transaction' => $transaction] = $this->createTransactionWithChat();

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/entity-links/preview?entity=transaction&entity_id='.$transaction->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.entity', 'transaction');
        $response->assertJsonPath('data.entity_id', $transaction->id);
        $response->assertJsonPath('data.url', '/transactions/'.$transaction->id);
        $response->assertJsonPath('data.title', 'Транзакция #'.$transaction->id);
        $response->assertJsonPath('data.subtitle', $this->expectedTransactionSubtitle());
    }

    public function test_entity_link_preview_returns_not_found_without_view_permission(): void
    {
        ['transaction' => $transaction] = $this->createTransactionWithChat();

        $this->grantCompanyPermissions($this->outsiderUser, $this->company, ['chats_view']);

        $response = $this->actingAsApi($this->outsiderUser)
            ->getJson('/api/entity-links/preview?entity=transaction&entity_id='.$transaction->id);

        $response->assertStatus(404);
    }

    public function test_entity_link_preview_returns_not_found_for_other_cash_register(): void
    {
        $allowedCashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
        ]);
        $foreignCashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
        ]);

        ['transaction' => $transaction] = $this->createTransactionWithChat($foreignCashRegister);

        $cashier = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $cashier->companies()->attach($this->company->id);
        $allowedCashRegister->users()->attach($cashier->id);

        $this->grantCompanyPermissions($cashier, $this->company, [
            'transactions_view_own',
            'cash_registers_view_own',
            'chats_view',
        ]);

        $response = $this->actingAsApi($cashier)
            ->getJson('/api/entity-links/preview?entity=transaction&entity_id='.$transaction->id);

        $response->assertStatus(404);
    }

    public function test_manual_entity_link_metadata_in_post_is_rejected(): void
    {
        ['transaction' => $transaction, 'chat' => $chat] = $this->createTransactionWithChat();

        $this->grantCompanyPermissions($this->adminUser, $this->company, [
            'chats_view',
            'chats_write',
            'chats_write_general',
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/chats/{$chat->id}/messages", [
                'body' => 'Проверь транзакцию',
                'metadata' => [
                    'type' => 'entity_link',
                    'entity' => 'transaction',
                    'entity_id' => $transaction->id,
                ],
            ]);

        $response->assertStatus(404);
    }

    public function test_send_entity_link_message_returns_not_found_without_access(): void
    {
        ['transaction' => $transaction, 'chat' => $chat] = $this->createTransactionWithChat();

        $this->grantCompanyPermissions($this->outsiderUser, $this->company, [
            'chats_view',
            'chats_write',
            'chats_write_general',
        ]);

        $response = $this->actingAsApi($this->outsiderUser)
            ->postJson("/api/chats/{$chat->id}/messages", [
                'body' => $this->transactionShareBody($transaction, 'Проверь транзакцию'),
            ]);

        $response->assertStatus(404);
    }

    public function test_chat_messages_redact_entity_link_metadata_for_unauthorized_viewer(): void
    {
        ['transaction' => $transaction, 'chat' => $chat] = $this->createTransactionWithChat();

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/chats/{$chat->id}/messages", [
                'body' => $this->transactionShareBody($transaction, 'Проверь транзакцию'),
            ])
            ->assertStatus(201);

        $this->grantCompanyPermissions($this->outsiderUser, $this->company, [
            'chats_view',
            'chats_view_all',
        ]);

        $response = $this->actingAsApi($this->outsiderUser)
            ->getJson("/api/chats/{$chat->id}/messages?tail=1");

        $response->assertStatus(200);
        $message = $this->findEntityLinkMessage($response->json('data') ?? [], (int) $transaction->id);
        $this->assertNotNull($message);
        $this->assertSame('transaction', $message['metadata']['entity'] ?? null);
        $this->assertSame((int) $transaction->id, (int) ($message['metadata']['entity_id'] ?? 0));
        $this->assertTrue($message['metadata']['restricted'] ?? false);
        $this->assertArrayNotHasKey('subtitle', $message['metadata']);
        $this->assertArrayNotHasKey('title', $message['metadata']);
    }

    public function test_entity_link_sends_message_with_metadata_from_body(): void
    {
        ['transaction' => $transaction, 'chat' => $chat] = $this->createTransactionWithChat();

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/chats/{$chat->id}/messages", [
                'body' => $this->transactionShareBody($transaction, 'Проверь транзакцию'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.metadata.entity', 'transaction');
        $response->assertJsonPath('data.metadata.entity_id', $transaction->id);
        $response->assertJsonPath('data.metadata.url', '/transactions/'.$transaction->id);
        $response->assertJsonMissingPath('data.metadata.subtitle');
        $response->assertJsonMissingPath('data.metadata.title');

        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $chat->id,
            'creator_id' => $this->adminUser->id,
        ]);
    }
}
