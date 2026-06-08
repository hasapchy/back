<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Transaction;
use Tests\Support\Concerns\BuildsDocumentPaymentApiContext;
use Tests\TestCase;

class DocumentPaymentTransactionCrudTest extends TestCase
{
    use BuildsDocumentPaymentApiContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDocumentPaymentApiContext();
    }

    public function test_index_lists_manual_order_payments_excluding_auto_debt(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);
        $manual = $this->storeManualOrderPayment($order);
        $this->createAutoDebtOrderTransaction($order);

        $response = $this->actingAsDocumentPaymentApi()
            ->getJson("/api/transactions?order_id={$order->id}&is_debt=0&per_page=50");

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? [];
        $ids = array_map(static fn ($row) => (int) ($row['id'] ?? 0), $items);
        $this->assertContains((int) $manual->id, $ids);
        foreach ($items as $row) {
            $this->assertNotSame(1, (int) ($row['is_debt'] ?? $row['isDebt'] ?? 0));
        }
    }

    public function test_update_manual_order_payment_amount_and_note_succeeds(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);
        $transaction = $this->storeManualOrderPayment($order);

        $response = $this->actingAsDocumentPaymentApi()->putJson("/api/transactions/{$transaction->id}", [
            'category_id' => $this->category->id,
            'orig_amount' => 250,
            'currency_id' => $this->currency->id,
            'note' => 'updated manual payment',
            'is_debt' => false,
        ]);

        $response->assertStatus(200);
        $transaction->refresh();
        $this->assertSame(250.0, (float) $transaction->orig_amount);
        $this->assertSame('updated manual payment', $transaction->note);
        $this->assertFalse((bool) $transaction->is_debt);
    }

    public function test_update_manual_order_payment_keeps_is_debt_false_when_omitted(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);
        $transaction = $this->storeManualOrderPayment($order);

        $response = $this->actingAsDocumentPaymentApi()->putJson("/api/transactions/{$transaction->id}", [
            'category_id' => $this->category->id,
            'orig_amount' => 150,
            'currency_id' => $this->currency->id,
        ]);

        $response->assertStatus(200);
        $this->assertFalse((bool) $transaction->fresh()->is_debt);
    }

    public function test_destroy_manual_order_payment_succeeds(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);
        $transaction = $this->storeManualOrderPayment($order);

        $response = $this->actingAsDocumentPaymentApi()
            ->deleteJson("/api/transactions/{$transaction->id}");

        $response->assertStatus(200);
        $this->assertTrue((bool) $transaction->fresh()->is_deleted);
    }

    public function test_destroy_auto_debt_order_transaction_forbidden(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);
        $debt = $this->createAutoDebtOrderTransaction($order);

        $response = $this->actingAsDocumentPaymentApi()
            ->deleteJson("/api/transactions/{$debt->id}");

        $response->assertStatus(403);
        $this->assertFalse((bool) $debt->fresh()->is_deleted);
    }

    public function test_update_auto_debt_order_transaction_forbidden(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);
        $debt = $this->createAutoDebtOrderTransaction($order);

        $response = $this->actingAsDocumentPaymentApi()->putJson("/api/transactions/{$debt->id}", [
            'category_id' => $this->category->id,
            'orig_amount' => 50,
            'currency_id' => $this->currency->id,
            'is_debt' => false,
        ]);

        $response->assertStatus(403);
    }

    public function test_store_order_payment_via_source_type_instead_of_order_id_field(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);

        $response = $this->postPayment([
            'source_type' => Order::class,
            'source_id' => $order->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
        ]);

        $response->assertStatus(200);
        $transaction = Transaction::query()->orderByDesc('id')->first();
        $this->assertSame(Order::class, $transaction->source_type);
        $this->assertSame($order->id, (int) $transaction->source_id);
    }

    public function test_store_contract_payment_update_and_destroy_manual_flow(): void
    {
        $contract = $this->createProjectContract();

        $store = $this->postPayment([
            'source_type' => 'App\\Models\\ProjectContract',
            'source_id' => $contract->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
        ]);
        $store->assertStatus(200);

        $transaction = Transaction::query()->orderByDesc('id')->first();
        $this->assertGreaterThan(0, $transaction->id);

        $update = $this->actingAsDocumentPaymentApi()->putJson("/api/transactions/{$transaction->id}", [
            'category_id' => $this->category->id,
            'orig_amount' => 80,
            'currency_id' => $this->currency->id,
            'note' => 'contract payment edited',
            'is_debt' => false,
        ]);
        $update->assertStatus(200);

        $destroy = $this->actingAsDocumentPaymentApi()->deleteJson("/api/transactions/{$transaction->id}");
        $destroy->assertStatus(200);
    }

    public function test_store_contract_payment_rejects_overpayment(): void
    {
        $contract = $this->createProjectContract(['amount' => 100, 'paid_amount' => 0]);

        $response = $this->postPayment([
            'source_type' => 'App\\Models\\ProjectContract',
            'source_id' => $contract->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
            'orig_amount' => 150,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['orig_amount']);
    }

    public function test_store_contract_second_payment_rejects_when_total_exceeds_contract(): void
    {
        $contract = $this->createProjectContract(['amount' => 100, 'paid_amount' => 0]);

        $this->postPayment([
            'source_type' => 'App\\Models\\ProjectContract',
            'source_id' => $contract->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
            'orig_amount' => 100,
        ])->assertStatus(200);

        $second = $this->postPayment([
            'source_type' => 'App\\Models\\ProjectContract',
            'source_id' => $contract->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
            'orig_amount' => 2,
        ]);

        $second->assertStatus(422);
        $second->assertJsonValidationErrors(['orig_amount']);
    }

    public function test_store_purchase_goods_payment_rejects_overpayment(): void
    {
        $purchase = $this->createWhPurchase(['amount' => 500]);

        $response = $this->postPayment([
            'type' => 0,
            'category_id' => $this->warehouseGoodsPaymentCategory->id,
            'orig_amount' => 600,
            'source_type' => 'App\\Models\\WhPurchase',
            'source_id' => $purchase->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => __('warehouse_purchase.goods_payment_exceeds_remaining')]);
    }

    public function test_store_purchase_payment_update_and_destroy_manual_flow(): void
    {
        $purchase = $this->createWhPurchase();

        $store = $this->postPayment([
            'source_type' => 'App\\Models\\WhPurchase',
            'source_id' => $purchase->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
        ]);
        $store->assertStatus(200);

        $transaction = Transaction::query()->orderByDesc('id')->first();

        $this->actingAsDocumentPaymentApi()->putJson("/api/transactions/{$transaction->id}", [
            'category_id' => $this->category->id,
            'orig_amount' => 90,
            'currency_id' => $this->currency->id,
            'is_debt' => false,
        ])->assertStatus(200);

        $this->actingAsDocumentPaymentApi()->deleteJson("/api/transactions/{$transaction->id}")
            ->assertStatus(200);
    }

    public function test_store_receipt_logistics_expense_without_supplier_allows_no_balance(): void
    {
        $receipt = $this->createWhReceiptWithProductLine([
            'supplier_id' => null,
            'client_balance_id' => null,
        ]);

        $this->postPayment([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'is_debt' => false,
            'type' => 0,
            'category_id' => $this->warehouseDeliveryExpenseCategory->id,
        ])->assertStatus(200);
    }

    public function test_destroy_auto_debt_wh_receipt_transaction_forbidden(): void
    {
        $receipt = $this->createWhReceiptWithProductLine();
        $debt = $this->createAutoDebtWhReceiptTransaction($receipt);

        $this->actingAsDocumentPaymentApi()
            ->deleteJson("/api/transactions/{$debt->id}")
            ->assertStatus(403);
    }

    public function test_store_rejects_is_debt_for_contract_and_purchase(): void
    {
        $contract = $this->createProjectContract();
        $purchase = $this->createWhPurchase();

        $cases = [
            [
                'source_type' => 'App\\Models\\ProjectContract',
                'source_id' => $contract->id,
            ],
            [
                'source_type' => 'App\\Models\\WhPurchase',
                'source_id' => $purchase->id,
            ],
        ];

        foreach ($cases as $payload) {
            $response = $this->postPayment(array_merge([
                'client_id' => $this->client->id,
                'client_balance_id' => $this->clientBalance->id,
                'is_debt' => true,
            ], $payload));
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['is_debt']);
        }
    }

    public function test_store_rejects_wrong_balance_for_contract_and_purchase(): void
    {
        $alternate = $this->createAlternateClientBalance();
        $contract = $this->createProjectContract();
        $purchase = $this->createWhPurchase();

        $this->postPayment([
            'source_type' => 'App\\Models\\ProjectContract',
            'source_id' => $contract->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $alternate->id,
            'is_debt' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['client_balance_id']);

        $this->postPayment([
            'source_type' => 'App\\Models\\WhPurchase',
            'source_id' => $purchase->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $alternate->id,
            'is_debt' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['client_balance_id']);
    }
}
