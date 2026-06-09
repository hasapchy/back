<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Warehouse;
use App\Models\Product;
use App\Enums\WhWriteoffReason;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use App\Models\Transaction;
use App\Models\WarehouseStock;
use App\Models\Client;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\WhWriteoff;
use Tests\Support\Concerns\SeedsTransactionCategoryBindings;
use Tests\TestCase;

class WarehouseWriteoffControllerTest extends TestCase
{
    use SeedsTransactionCategoryBindings;

    protected User $adminUser;
    protected Company $company;
    protected Warehouse $warehouse;
    protected Product $product;

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
        $this->seedStandardTransactionCategoryBindings($this->company, $this->adminUser);
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
        $supplier = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $supplier->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $receiptProduct = WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'price' => 100,
        ]);
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
        $supplier = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
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
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $supplier->id,
            'creator_id' => $this->adminUser->id,
            'cash_id' => $usdCashRegister->id,
            'orig_currency_id' => $usdCurrency->id,
        ]);
        $receiptProduct = WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'price' => 20,
            'orig_unit_price' => 10,
            'orig_currency_id' => $usdCurrency->id,
        ]);
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
        ]);

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
        $debtTx = Transaction::query()
            ->where('source_type', WhWriteoff::class)
            ->where('source_id', $writeoffId)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->first();

        $this->assertNotNull($debtTx);
        $this->assertEqualsWithDelta(50.0, (float) $debtTx->orig_amount, 0.01);
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
}

