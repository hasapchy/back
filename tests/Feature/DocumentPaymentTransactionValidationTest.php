<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\Concerns\BuildsDocumentPaymentApiContext;
use Tests\TestCase;

class DocumentPaymentTransactionValidationTest extends TestCase
{
    use BuildsDocumentPaymentApiContext;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDocumentPaymentApiContext();
    }

    public function test_store_manual_order_payment_succeeds_with_matching_balance(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);

        $this->postPayment([
            'order_id' => $order->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
        ])->assertStatus(200);
    }

    public function test_store_manual_order_payment_rejects_wrong_balance(): void
    {
        $otherBalance = $this->createAlternateClientBalance();
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);

        $this->postPayment([
            'order_id' => $order->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $otherBalance->id,
            'is_debt' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['client_balance_id']);
    }

    public function test_store_manual_order_payment_rejects_missing_balance_when_document_has_balance(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);

        $this->postPayment([
            'order_id' => $order->id,
            'client_id' => $this->client->id,
            'is_debt' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['client_balance_id']);
    }

    public function test_store_manual_order_payment_requires_balance_when_order_has_client_without_document_balance(): void
    {
        $order = $this->createOrder(['client_balance_id' => null]);

        $this->postPayment([
            'order_id' => $order->id,
            'client_id' => $this->client->id,
            'is_debt' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['client_balance_id']);

        $this->postPayment([
            'order_id' => $order->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
        ])->assertStatus(200);
    }

    public function test_store_rejects_manual_is_debt_for_order(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);

        $this->postPayment([
            'order_id' => $order->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['is_debt']);
    }

    public function test_store_standalone_transaction_without_document_link_allows_no_balance(): void
    {
        $this->postPayment(['is_debt' => false])->assertStatus(200);
    }

    public function test_store_wh_receipt_goods_payment_requires_document_balance(): void
    {
        $receipt = $this->createWhReceipt();

        $this->postPayment([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->createAlternateClientBalance()->id,
            'category_id' => 6,
            'is_debt' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['client_balance_id']);

        $this->postPayment([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'category_id' => 6,
            'is_debt' => false,
        ])->assertStatus(200);
    }

    public function test_store_wh_receipt_logistics_payment_allows_other_supplier_balance(): void
    {
        $receipt = $this->createWhReceipt();
        $alternate = $this->createAlternateClientBalance();

        $this->postPayment([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $alternate->id,
            'category_id' => 16,
            'is_debt' => false,
        ])->assertStatus(200);
    }

    public function test_store_wh_receipt_delivery_expense_without_client_balance(): void
    {
        $receipt = $this->createWhReceipt();

        $this->postPayment([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'category_id' => 16,
            'is_debt' => false,
        ])->assertStatus(200);
    }

    public function test_store_wh_receipt_delivery_expense_allows_manual_is_debt_with_supplier_balance(): void
    {
        $receipt = $this->createWhReceipt();

        $this->postPayment([
            'source_type' => 'App\\Models\\WhReceipt',
            'source_id' => $receipt->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'category_id' => 16,
            'is_debt' => true,
        ])->assertStatus(200);
    }

    public function test_store_wh_purchase_payment_requires_matching_document_balance(): void
    {
        $purchase = $this->createWhPurchase();

        $this->postPayment([
            'source_type' => 'App\\Models\\WhPurchase',
            'source_id' => $purchase->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
        ])->assertStatus(200);
    }

    public function test_store_project_contract_payment_requires_matching_balance(): void
    {
        $contract = $this->createProjectContract();

        $this->postPayment([
            'source_type' => 'App\\Models\\ProjectContract',
            'source_id' => $contract->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'is_debt' => false,
        ])->assertStatus(200);
    }

    public function test_update_manual_order_linked_transaction_rejects_is_debt_true(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);
        $transaction = $this->storeManualOrderPayment($order);

        $this->actingAsDocumentPaymentApi()->putJson("/api/transactions/{$transaction->id}", [
            'category_id' => $this->category->id,
            'is_debt' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['is_debt']);
    }
}
