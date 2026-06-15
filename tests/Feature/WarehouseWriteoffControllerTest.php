<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Warehouse;
use App\Models\Product;
use App\Enums\WhWriteoffReason;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use App\Models\Transaction;
use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;
use App\Repositories\TransactionsRepository;
use App\Repositories\WarehouseReceiptRepository;
use App\Services\WarehouseDocumentPaymentStatusService;
use App\Services\WarehouseReturnSupplierSettlementService;
use App\Services\WarehouseReceiptGoodsPaymentLimitService;
use App\Support\TransactionCategoryBindingKeys;
use Tests\Support\Concerns\SeedsWarehouseTransactionCategoryBindings;
use Tests\TestCase;

class WarehouseWriteoffControllerTest extends TestCase
{
    use SeedsWarehouseTransactionCategoryBindings;

    protected User $adminUser;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Product $product;

    protected CashRegister $cashRegister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
        $this->ensureDefaultCurrencyForCompany($this->company);
        $currency = Currency::query()
            ->where('company_id', $this->company->id)
            ->where('is_default', true)
            ->firstOrFail();
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $currency->id,
            'balance' => 100000,
            'is_working_minus' => true,
        ]);
        $this->seedWarehouseGoodsPaymentBindings($this->company, $this->adminUser);
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    public function test_store_warehouse_writeoff_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_writeoffs', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['warehouse_id', 'reason', 'products']);
    }

    public function test_store_warehouse_writeoff_success(): void
    {
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
        ]);

        $data = [
            'warehouse_id' => $this->warehouse->id,
            'reason' => 'defect',
            'note' => 'Test writeoff',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_writeoffs', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => __('api.writeoff.created')]);
    }

    public function test_update_warehouse_writeoff_success(): void
    {
        $writeoff = WhWriteoff::factory()->create([
            'warehouse_id' => $this->warehouse->id,
        ]);

        $data = [
            'warehouse_id' => $this->warehouse->id,
            'reason' => 'consumable',
            'note' => 'Updated writeoff',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 20,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/warehouse_writeoffs/{$writeoff->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => __('api.writeoff.updated')]);
    }

    public function test_destroy_warehouse_writeoff_success(): void
    {
        $writeoff = WhWriteoff::factory()->create([
            'warehouse_id' => $this->warehouse->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/warehouse_writeoffs/{$writeoff->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => __('api.writeoff.deleted')]);
    }

    public function test_return_supplier_allows_writeoff_on_another_warehouse_after_stock_transfer(): void
    {
        $warehouseB = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        ['receipt' => $receipt, 'receiptProduct' => $receiptProduct] = $this->createEligibleReceipt(10, 100);
        WarehouseStock::query()->create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_writeoffs', [
                'warehouse_id' => $warehouseB->id,
                'reason' => WhWriteoffReason::ReturnSupplier->value,
                'source_receipt_id' => $receipt->id,
                'note' => 'Return after transfer',
                'products' => [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => 5,
                        'source_receipt_product_id' => $receiptProduct->id,
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => __('api.writeoff.created')]);
        $this->assertDatabaseHas('warehouse_stocks', [
            'warehouse_id' => $warehouseB->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
        ]);
    }

    public function test_return_supplier_transaction_uses_receipt_document_currency_amount(): void
    {
        $usdCurrency = Currency::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => false,
            'is_report' => false,
        ]);
        CurrencyHistory::query()->create([
            'currency_id' => $usdCurrency->id,
            'company_id' => $this->company->id,
            'exchange_rate' => 2,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => null,
        ]);
        $usdCashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $usdCurrency->id,
        ]);
        ['receipt' => $receipt, 'receiptProduct' => $receiptProduct] = $this->createEligibleReceipt(
            5,
            20,
            'approved',
            null,
            $usdCashRegister
        );
        $receiptProduct->update([
            'orig_unit_price' => 10,
            'orig_currency_id' => $usdCurrency->id,
        ]);
        WarehouseStock::query()->updateOrCreate(
            [
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $this->product->id,
            ],
            ['quantity' => 5]
        );

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_writeoffs', [
                'warehouse_id' => $this->warehouse->id,
                'reason' => WhWriteoffReason::ReturnSupplier->value,
                'source_receipt_id' => $receipt->id,
                'note' => 'Return',
                'products' => [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => 5,
                        'source_receipt_product_id' => $receiptProduct->id,
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $writeoffId = (int) WhWriteoff::query()->orderByDesc('id')->value('id');
        $payableCategoryId = (int) $this->transactionCategoryBindingsByKey[
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION
        ]->id;
        $payableTx = Transaction::query()
            ->where('source_type', WhWriteoff::class)
            ->where('source_id', $writeoffId)
            ->where('category_id', $payableCategoryId)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->first();

        $this->assertNotNull($payableTx);
        $this->assertEqualsWithDelta(50.0, (float) $payableTx->orig_amount, 0.01);
        $this->assertSame(0, Transaction::query()
            ->where('source_type', WhWriteoff::class)
            ->where('source_id', $writeoffId)
            ->where('is_debt', false)
            ->where('is_deleted', false)
            ->count());
    }

    public function test_return_supplier_unpaid_creates_payable_reduction_only(): void
    {
        ['receipt' => $receipt, 'receiptProduct' => $receiptProduct] = $this->createEligibleReceipt(10, 100);
        WarehouseStock::query()->updateOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id],
            ['quantity' => 10]
        );

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_writeoffs', [
            'warehouse_id' => $this->warehouse->id,
            'reason' => WhWriteoffReason::ReturnSupplier->value,
            'source_receipt_id' => $receipt->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 3,
                    'source_receipt_product_id' => $receiptProduct->id,
                ],
            ],
        ]);
        $response->assertStatus(200);

        $writeoffId = (int) WhWriteoff::query()->orderByDesc('id')->value('id');
        $payableCategoryId = (int) $this->transactionCategoryBindingsByKey[
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION
        ]->id;
        $creditCategoryId = (int) $this->transactionCategoryBindingsByKey[
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_SUPPLIER_CREDIT
        ]->id;

        $this->assertSame(1, Transaction::query()
            ->where('source_type', WhWriteoff::class)
            ->where('source_id', $writeoffId)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->count());
        $this->assertNotNull(Transaction::query()
            ->where('source_id', $writeoffId)
            ->where('category_id', $payableCategoryId)
            ->first());
        $this->assertNull(Transaction::query()
            ->where('source_id', $writeoffId)
            ->where('category_id', $creditCategoryId)
            ->first());

        $receipt->refresh();
        $paymentService = app(WarehouseDocumentPaymentStatusService::class);
        $this->assertEqualsWithDelta(300.0, $paymentService->sumPayableReductionDefaultForReceipt((int) $receipt->id), 0.01);
        $this->assertEqualsWithDelta(700.0, $paymentService->effectiveRemainingDefault($receipt), 0.01);

        $linked = app(WarehouseReturnSupplierSettlementService::class)->linkedReturnsForReceipt((int) $receipt->id);
        $this->assertCount(1, $linked);
        $this->assertEqualsWithDelta(300.0, $linked[0]['return_amount'], 0.01);
        $this->assertEqualsWithDelta(300.0, $linked[0]['unpaid_portion'], 0.01);
        $this->assertEqualsWithDelta(0.0, $linked[0]['paid_portion'], 0.01);
    }

    public function test_return_supplier_fully_paid_creates_supplier_credit_only(): void
    {
        ['receipt' => $receipt, 'receiptProduct' => $receiptProduct] = $this->createEligibleReceipt(10, 100);
        $this->payReceiptGoodsInFull($receipt);
        WarehouseStock::query()->updateOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id],
            ['quantity' => 10]
        );

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_writeoffs', [
            'warehouse_id' => $this->warehouse->id,
            'reason' => WhWriteoffReason::ReturnSupplier->value,
            'source_receipt_id' => $receipt->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 3,
                    'source_receipt_product_id' => $receiptProduct->id,
                ],
            ],
        ]);
        $response->assertStatus(200);

        $writeoffId = (int) WhWriteoff::query()->orderByDesc('id')->value('id');
        $payableCategoryId = (int) $this->transactionCategoryBindingsByKey[
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION
        ]->id;
        $creditCategoryId = (int) $this->transactionCategoryBindingsByKey[
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_SUPPLIER_CREDIT
        ]->id;

        $creditTx = Transaction::query()
            ->where('source_id', $writeoffId)
            ->where('category_id', $creditCategoryId)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->first();
        $this->assertNotNull($creditTx);
        $this->assertEqualsWithDelta(300.0, (float) $creditTx->def_amount, 0.01);
        $this->assertNull(Transaction::query()
            ->where('source_id', $writeoffId)
            ->where('category_id', $payableCategoryId)
            ->first());

        $receipt->refresh();
        $paymentService = app(WarehouseDocumentPaymentStatusService::class);
        $this->assertEqualsWithDelta(0.0, $paymentService->effectiveRemainingDefault($receipt), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $receipt->paid_amount, 0.01);
        $clientBalanceId = (int) Transaction::query()
            ->where('source_type', WhReceipt::class)
            ->where('source_id', (int) $receipt->id)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->value('client_balance_id');
        $clientBalance = ClientBalance::query()->findOrFail($clientBalanceId);
        $this->assertEqualsWithDelta(300.0, (float) $clientBalance->balance, 0.01);
    }

    public function test_return_supplier_partial_fifo_split(): void
    {
        ['receipt' => $receipt, 'receiptProduct' => $receiptProduct] = $this->createEligibleReceipt(10, 100);
        $defaultCurrency = Currency::query()
            ->where('company_id', $this->company->id)
            ->where('is_default', true)
            ->firstOrFail();
        app(TransactionsRepository::class)->createItem([
            'type' => 0,
            'creator_id' => $this->adminUser->id,
            'orig_amount' => 600,
            'amount' => 600,
            'currency_id' => (int) $defaultCurrency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $this->warehouseGoodsPaymentCategory->id,
            'client_id' => (int) $receipt->supplier_id,
            'project_id' => null,
            'exchange_rate' => 1,
            'date' => now()->toDateTimeString(),
            'is_debt' => false,
            'source_type' => WhReceipt::class,
            'source_id' => (int) $receipt->id,
        ], true);
        $receipt->refresh();
        WarehouseStock::query()->updateOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id],
            ['quantity' => 10]
        );

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_writeoffs', [
            'warehouse_id' => $this->warehouse->id,
            'reason' => WhWriteoffReason::ReturnSupplier->value,
            'source_receipt_id' => $receipt->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'source_receipt_product_id' => $receiptProduct->id,
                ],
            ],
        ]);
        $response->assertStatus(200);

        $writeoffId = (int) WhWriteoff::query()->orderByDesc('id')->value('id');
        $payableCategoryId = (int) $this->transactionCategoryBindingsByKey[
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION
        ]->id;
        $creditCategoryId = (int) $this->transactionCategoryBindingsByKey[
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_SUPPLIER_CREDIT
        ]->id;

        $payableTx = Transaction::query()
            ->where('source_id', $writeoffId)
            ->where('category_id', $payableCategoryId)
            ->first();
        $creditTx = Transaction::query()
            ->where('source_id', $writeoffId)
            ->where('category_id', $creditCategoryId)
            ->first();

        $this->assertNotNull($payableTx);
        $this->assertNotNull($creditTx);
        $this->assertEqualsWithDelta(400.0, (float) $payableTx->def_amount, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $creditTx->def_amount, 0.01);
    }

    public function test_return_supplier_does_not_increase_receipt_paid_amount(): void
    {
        ['receipt' => $receipt, 'receiptProduct' => $receiptProduct] = $this->createEligibleReceipt(10, 100);
        $this->payReceiptGoodsInFull($receipt);
        $paidBefore = (float) WhReceipt::query()->findOrFail($receipt->id)->paid_amount;
        WarehouseStock::query()->updateOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id],
            ['quantity' => 10]
        );

        $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_writeoffs', [
            'warehouse_id' => $this->warehouse->id,
            'reason' => WhWriteoffReason::ReturnSupplier->value,
            'source_receipt_id' => $receipt->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 3,
                    'source_receipt_product_id' => $receiptProduct->id,
                ],
            ],
        ])->assertStatus(200);

        $writeoffId = (int) WhWriteoff::query()->orderByDesc('id')->value('id');
        $defaultCurrency = Currency::query()
            ->where('company_id', $this->company->id)
            ->where('is_default', true)
            ->firstOrFail();
        $incomeCategoryId = (int) $this->transactionCategoryBindingsByKey[
            TransactionCategoryBindingKeys::TRANSACTION_DEFAULT_INCOME
        ]->id;
        $clientBalanceId = (int) Transaction::query()
            ->where('source_type', WhReceipt::class)
            ->where('source_id', (int) $receipt->id)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->value('client_balance_id');
        app(TransactionsRepository::class)->createItem([
            'type' => 1,
            'creator_id' => $this->adminUser->id,
            'orig_amount' => 100,
            'amount' => 100,
            'currency_id' => (int) $defaultCurrency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $incomeCategoryId,
            'client_id' => (int) $receipt->supplier_id,
            'client_balance_id' => $clientBalanceId,
            'project_id' => null,
            'exchange_rate' => 1,
            'date' => now()->toDateTimeString(),
            'is_debt' => false,
            'source_type' => WhWriteoff::class,
            'source_id' => $writeoffId,
        ], true);

        $receipt->refresh();
        $this->assertEqualsWithDelta($paidBefore, (float) $receipt->paid_amount, 0.01);
    }

    public function test_receipt_delete_blocked_when_linked_return_exists(): void
    {
        ['receipt' => $receipt, 'receiptProduct' => $receiptProduct] = $this->createEligibleReceipt(10, 100);
        WarehouseStock::query()->updateOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id],
            ['quantity' => 10]
        );

        $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_writeoffs', [
            'warehouse_id' => $this->warehouse->id,
            'reason' => WhWriteoffReason::ReturnSupplier->value,
            'source_receipt_id' => $receipt->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'source_receipt_product_id' => $receiptProduct->id,
                ],
            ],
        ])->assertStatus(200);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/warehouse_receipts/{$receipt->id}");

        $response->assertStatus(400);
        $response->assertJsonFragment([
            'error' => __('warehouse_receipt.delete_error', [
                'message' => __('warehouse_return.receipt_has_linked_returns'),
            ]),
        ]);
    }

    public function test_return_supplier_delete_rolls_back_stock_and_transactions(): void
    {
        ['receipt' => $receipt, 'receiptProduct' => $receiptProduct] = $this->createEligibleReceipt(10, 100);
        WarehouseStock::query()->updateOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id],
            ['quantity' => 10]
        );

        $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_writeoffs', [
            'warehouse_id' => $this->warehouse->id,
            'reason' => WhWriteoffReason::ReturnSupplier->value,
            'source_receipt_id' => $receipt->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 4,
                    'source_receipt_product_id' => $receiptProduct->id,
                ],
            ],
        ])->assertStatus(200);

        $writeoffId = (int) WhWriteoff::query()->orderByDesc('id')->value('id');
        $stockAfterReturn = (float) WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEqualsWithDelta(6.0, $stockAfterReturn, 1e-9);

        $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/warehouse_writeoffs/{$writeoffId}")
            ->assertStatus(200);

        $stockAfterDelete = (float) WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEqualsWithDelta(10.0, $stockAfterDelete, 1e-9);
        $this->assertSame(0, Transaction::query()
            ->where('source_type', WhWriteoff::class)
            ->where('source_id', $writeoffId)
            ->where('is_deleted', false)
            ->count());

        $receipt->refresh();
        $paymentService = app(WarehouseDocumentPaymentStatusService::class);
        $this->assertEqualsWithDelta(1000.0, $paymentService->effectiveRemainingDefault($receipt), 0.01);
    }

    public function test_public_api_blocks_generated_return_binding_on_writeoff(): void
    {
        ['receipt' => $receipt, 'receiptProduct' => $receiptProduct] = $this->createEligibleReceipt(10, 100);
        WarehouseStock::query()->updateOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id],
            ['quantity' => 10]
        );

        $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_writeoffs', [
            'warehouse_id' => $this->warehouse->id,
            'reason' => WhWriteoffReason::ReturnSupplier->value,
            'source_receipt_id' => $receipt->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'source_receipt_product_id' => $receiptProduct->id,
                ],
            ],
        ])->assertStatus(200);

        $writeoffId = (int) WhWriteoff::query()->orderByDesc('id')->value('id');
        $payableCategoryId = (int) $this->transactionCategoryBindingsByKey[
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION
        ]->id;
        $defaultCurrency = Currency::query()
            ->where('company_id', $this->company->id)
            ->where('is_default', true)
            ->firstOrFail();

        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $this->expectException(\RuntimeException::class);
        app(TransactionsRepository::class)->createItem([
            'type' => 1,
            'creator_id' => $this->adminUser->id,
            'orig_amount' => 50,
            'amount' => 50,
            'currency_id' => (int) $defaultCurrency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $payableCategoryId,
            'client_id' => (int) $receipt->supplier_id,
            'project_id' => null,
            'exchange_rate' => 1,
            'date' => now()->toDateTimeString(),
            'is_debt' => true,
            'source_type' => WhWriteoff::class,
            'source_id' => $writeoffId,
        ], true);
    }

    public function test_index_filters_by_reason_and_exclude_reason(): void
    {
        $returnWriteoff = WhWriteoff::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'creator_id' => $this->adminUser->id,
            'reason' => WhWriteoffReason::ReturnSupplier,
        ]);
        $defectWriteoff = WhWriteoff::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'creator_id' => $this->adminUser->id,
            'reason' => WhWriteoffReason::Defect,
        ]);

        $onlyReturns = $this->actingAsApi($this->adminUser)
            ->getJson('/api/warehouse_writeoffs?reason=return_supplier&per_page=50');

        $onlyReturns->assertStatus(200);
        $ids = collect($onlyReturns->json('data.items'))->pluck('id')->all();
        $this->assertContains($returnWriteoff->id, $ids);
        $this->assertNotContains($defectWriteoff->id, $ids);

        $excludingReturns = $this->actingAsApi($this->adminUser)
            ->getJson('/api/warehouse_writeoffs?exclude_reason=return_supplier&per_page=50');

        $excludingReturns->assertStatus(200);
        $idsEx = collect($excludingReturns->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($returnWriteoff->id, $idsEx);
        $this->assertContains($defectWriteoff->id, $idsEx);

        $reasonOverridesExclude = $this->actingAsApi($this->adminUser)
            ->getJson('/api/warehouse_writeoffs?reason=defect&exclude_reason=return_supplier&per_page=50');

        $reasonOverridesExclude->assertStatus(200);
        $idsBoth = collect($reasonOverridesExclude->json('data.items'))->pluck('id')->all();
        $this->assertContains($defectWriteoff->id, $idsBoth);
        $this->assertNotContains($returnWriteoff->id, $idsBoth);
    }

    /**
     * @return array{receipt: WhReceipt, receiptProduct: \App\Models\WhReceiptProduct, supplier: Client, cashRegister: CashRegister}
     */
    private function createEligibleReceipt(
        float $qty = 10,
        float $price = 100.0,
        string $status = 'approved',
        ?Client $supplier = null,
        ?CashRegister $cashRegister = null,
    ): array {
        $supplier ??= Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $cashRegister ??= $this->cashRegister;

        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $receiptId = app(WarehouseReceiptRepository::class)->createItem([
            'client_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'date' => now()->toDateTimeString(),
            'note' => 'eligible receipt',
            'status' => 'draft',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $qty,
                    'price' => $price,
                ],
            ],
        ]);

        if (in_array($status, ['approved', 'completed'], true)) {
            $this->approveReceiptForCompletion((int) $receiptId);
        }

        $receipt = WhReceipt::query()->with('products')->findOrFail($receiptId);
        $receiptProduct = $receipt->products->first();
        if (! $receiptProduct) {
            throw new \RuntimeException('Receipt product missing in test setup');
        }

        return [
            'receipt' => $receipt,
            'receiptProduct' => $receiptProduct,
            'supplier' => $supplier,
            'cashRegister' => $cashRegister,
        ];
    }

    /**
     * @return void
     */
    private function approveReceiptForCompletion(int $receiptId): void
    {
        $receipt = WhReceipt::query()->with('products')->findOrFail($receiptId);
        $products = [];
        foreach ($receipt->products as $line) {
            $products[] = [
                'product_id' => (int) $line->product_id,
                'quantity' => (float) $line->quantity,
                'price' => (float) ($line->orig_unit_price ?? $line->price),
            ];
        }

        app(WarehouseReceiptRepository::class)->updateReceipt($receiptId, [
            'client_id' => (int) $receipt->supplier_id,
            'warehouse_id' => (int) $receipt->warehouse_id,
            'cash_id' => $receipt->cash_id,
            'date' => $receipt->date,
            'note' => $receipt->note,
            'status' => 'approved',
            'products' => $products,
        ]);
    }

    private function payReceiptGoodsInFull(WhReceipt $receipt, ?ClientBalance $clientBalance = null): void
    {
        $defaultCurrency = Currency::query()
            ->where('company_id', $this->company->id)
            ->where('is_default', true)
            ->firstOrFail();
        $total = app(WarehouseReceiptGoodsPaymentLimitService::class)->goodsTotalDefault($receipt);

        if ($clientBalance === null) {
            $clientBalanceId = (int) Transaction::query()
                ->where('source_type', WhReceipt::class)
                ->where('source_id', (int) $receipt->id)
                ->where('is_debt', true)
                ->where('is_deleted', false)
                ->value('client_balance_id');
        } else {
            $clientBalanceId = (int) $clientBalance->id;
        }

        app(TransactionsRepository::class)->createItem([
            'type' => 0,
            'creator_id' => $this->adminUser->id,
            'orig_amount' => $total,
            'amount' => $total,
            'currency_id' => (int) $defaultCurrency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $this->warehouseGoodsPaymentCategory->id,
            'client_id' => (int) $receipt->supplier_id,
            'client_balance_id' => $clientBalanceId,
            'project_id' => null,
            'exchange_rate' => 1,
            'date' => now()->toDateTimeString(),
            'is_debt' => false,
            'source_type' => WhReceipt::class,
            'source_id' => (int) $receipt->id,
        ], true);

        $receipt->refresh();
    }
}
