<?php

namespace Tests\Support\Concerns;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use Illuminate\Testing\TestResponse;

trait BuildsDocumentPaymentApiContext
{
    protected User $adminUser;

    protected Company $company;

    protected CashRegister $cashRegister;

    protected Currency $currency;

    protected Client $client;

    protected TransactionCategory $category;

    protected TransactionCategory $outcomeCategory;

    protected Warehouse $warehouse;

    protected Product $product;

    protected ClientBalance $clientBalance;

    protected function bootDocumentPaymentApiContext(): void
    {
        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->currency = Currency::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => true,
            'is_report' => true,
        ]);
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
            'is_cash' => true,
        ]);
        $this->category = TransactionCategory::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => 1,
        ]);
        $this->outcomeCategory = TransactionCategory::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => 0,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->clientBalance = ClientBalance::query()->create([
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
            'type' => 1,
            'balance' => 0,
            'is_default' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function paymentPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 1,
            'orig_amount' => 100,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $this->category->id,
            'date' => '2025-06-01',
            'note' => 'document payment test',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'cash_id' => $this->cashRegister->id,
            'status_id' => OrderStatus::factory()->create()->id,
        ], $overrides));
    }

    protected function createWhReceipt(array $overrides = []): WhReceipt
    {
        return WhReceipt::factory()->create(array_merge([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
        ], $overrides));
    }

    protected function createWhReceiptWithProductLine(array $receiptOverrides = []): WhReceipt
    {
        $receipt = $this->createWhReceipt($receiptOverrides);
        WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'price' => 10,
        ]);

        return $receipt;
    }

    protected function createWhPurchase(array $overrides = []): WhPurchase
    {
        return WhPurchase::query()->create(array_merge([
            'supplier_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'draft',
            'date' => now(),
            'amount' => 500,
        ], $overrides));
    }

    protected function createProjectContract(array $overrides = []): ProjectContract
    {
        $project = Project::factory()->create([
            'client_id' => $this->client->id,
        ]);

        return ProjectContract::factory()->create(array_merge([
            'project_id' => $project->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'currency_id' => $this->currency->id,
        ], $overrides));
    }

    protected function createAlternateClientBalance(): ClientBalance
    {
        return ClientBalance::query()->create([
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
            'type' => 1,
            'balance' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     */
    protected function postPayment(array $payloadOverrides = []): TestResponse
    {
        return $this->actingAsDocumentPaymentApi()
            ->postJson('/api/transactions', $this->paymentPayload($payloadOverrides));
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     */
    protected function storeManualOrderPayment(Order $order, array $payloadOverrides = []): Transaction
    {
        $response = $this->postPayment(array_merge([
            'order_id' => $order->id,
            'client_id' => $this->client->id,
            'client_balance_id' => $order->client_balance_id ?? $this->clientBalance->id,
            'is_debt' => false,
        ], $payloadOverrides));
        $response->assertStatus(200);

        $transaction = Transaction::query()
            ->where('client_id', $this->client->id)
            ->where('is_debt', false)
            ->orderByDesc('id')
            ->first();

        $this->assertInstanceOf(Transaction::class, $transaction);

        return $transaction;
    }

    protected function createAutoDebtOrderTransaction(Order $order): Transaction
    {
        return Transaction::query()->create(
            $this->autoDebtTransactionAttributes([
                'type' => 1,
                'source_type' => Order::class,
                'source_id' => $order->id,
                'client_balance_id' => $order->client_balance_id ?? $this->clientBalance->id,
            ]),
        );
    }

    protected function createAutoDebtWhReceiptTransaction(WhReceipt $receipt): Transaction
    {
        return Transaction::query()->create(
            $this->autoDebtTransactionAttributes([
                'type' => 0,
                'source_type' => WhReceipt::class,
                'source_id' => $receipt->id,
                'client_balance_id' => $receipt->client_balance_id ?? $this->clientBalance->id,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function autoDebtTransactionAttributes(array $overrides = []): array
    {
        return array_merge([
            'type' => 1,
            'is_debt' => true,
            'orig_amount' => 500,
            'amount' => 500,
            'exchange_rate' => 1,
            'rep_rate' => 1,
            'rep_amount' => 500,
            'def_rate' => 1,
            'def_amount' => 500,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'category_id' => $this->category->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'date' => now(),
            'is_deleted' => false,
        ], $overrides);
    }

    protected function actingAsDocumentPaymentApi()
    {
        return $this->withApiTokenForCompany($this->adminUser, (int) $this->company->id);
    }
}
