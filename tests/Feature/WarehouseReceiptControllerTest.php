<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Unit;
use App\Models\Client;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use App\Models\Transaction;
use App\Models\WarehouseStock;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Repositories\WarehouseReceiptRepository;
use App\Services\CacheService;
use Tests\TestCase;

class WarehouseReceiptControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;
    protected Warehouse $warehouse;
    protected Product $product;
    protected Client $client;
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
        $this->client = \App\Models\Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $currency = Currency::query()
            ->where('company_id', $this->company->id)
            ->where('is_default', true)
            ->first()
            ?? Currency::factory()->create([
                'company_id' => $this->company->id,
                'is_default' => true,
                'is_report' => true,
            ]);
        if (! Currency::query()->where('company_id', $this->company->id)->where('is_report', true)->exists()) {
            Currency::factory()->create([
                'company_id' => $this->company->id,
                'is_default' => false,
                'is_report' => true,
            ]);
        }
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $currency->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_store_warehouse_receipt_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_receipts', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_id', 'warehouse_id', 'products']);
    }

    public function test_store_warehouse_receipt_success(): void
    {
        $data = [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'price' => 100.00,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_receipts', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'РћРїСЂРёС…РѕРґРѕРІР°РЅРёРµ СЃРѕР·РґР°РЅРѕ']);

        $receiptId = (int) WhReceipt::query()->orderByDesc('id')->value('id');
        $this->assertDatabaseHas('wh_receipts', [
            'id' => $receiptId,
            'orig_amount' => 1000,
            'orig_currency_id' => $this->cashRegister->currency_id,
            'amount' => 1000,
        ]);
        $this->assertDatabaseHas('wh_receipt_products', [
            'receipt_id' => $receiptId,
            'product_id' => $this->product->id,
            'orig_unit_price' => 100,
            'orig_currency_id' => $this->cashRegister->currency_id,
            'price' => 100,
        ]);
    }

    public function test_store_warehouse_receipt_converts_document_currency_to_default_amount(): void
    {
        $defaultCurrency = Currency::query()
            ->where('company_id', $this->company->id)
            ->where('is_default', true)
            ->firstOrFail();
        $usdCurrency = Currency::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => false,
            'is_report' => false,
        ]);
        CurrencyHistory::query()->create([
            'currency_id' => $defaultCurrency->id,
            'company_id' => $this->company->id,
            'exchange_rate' => 1,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => null,
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

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_receipts', [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $usdCashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'price' => 10,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $receiptId = (int) WhReceipt::query()->orderByDesc('id')->value('id');
        $this->assertDatabaseHas('wh_receipts', [
            'id' => $receiptId,
            'orig_amount' => 50,
            'orig_currency_id' => $usdCurrency->id,
            'amount' => 100,
        ]);
        $this->assertDatabaseHas('wh_receipt_products', [
            'receipt_id' => $receiptId,
            'product_id' => $this->product->id,
            'orig_unit_price' => 10,
            'orig_currency_id' => $usdCurrency->id,
            'price' => 20,
        ]);
    }

    public function test_store_warehouse_receipt_rejects_inconsistent_orig_quantity(): void
    {
        $piece = Unit::create(['name' => 'Piece r '.uniqid(), 'short_name' => 'С€С‚']);
        $box = Unit::create(['name' => 'Box r '.uniqid(), 'short_name' => 'РєРѕСЂ']);
        $this->product->update(['unit_id' => $piece->id]);
        ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'parent_unit_id' => $box->id,
            'child_unit_id' => $piece->id,
            'quantity' => '12',
        ]);

        $data = [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 100,
                    'price' => 100.00,
                    'orig_unit_id' => $box->id,
                    'orig_quantity' => 12,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_receipts', $data);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['products.0.orig_unit_id']);
    }

    public function test_update_warehouse_receipt_success(): void
    {
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $data = [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 20,
                    'price' => 200.00,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/warehouse_receipts/{$receipt->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'РћРїСЂРёС…РѕРґРѕРІР°РЅРёРµ РѕР±РЅРѕРІР»РµРЅРѕ']);
    }

    public function test_update_draft_receipt_note_preserves_foreign_currency_amounts(): void
    {
        $defaultCurrency = Currency::query()
            ->where('company_id', $this->company->id)
            ->where('is_default', true)
            ->firstOrFail();
        $usdCurrency = Currency::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => false,
            'is_report' => false,
        ]);
        CurrencyHistory::query()->create([
            'currency_id' => $defaultCurrency->id,
            'company_id' => $this->company->id,
            'exchange_rate' => 1,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => null,
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

        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_receipts', [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $usdCashRegister->id,
            'status' => 'draft',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'price' => 10,
                ],
            ],
        ]);
        $createResponse->assertStatus(200);
        $receiptId = (int) WhReceipt::query()->orderByDesc('id')->value('id');

        $updateResponse = $this->actingAsApi($this->adminUser)->putJson("/api/warehouse_receipts/{$receiptId}", [
            'note' => 'only note changed',
        ]);
        $updateResponse->assertStatus(200);

        $this->assertDatabaseHas('wh_receipts', [
            'id' => $receiptId,
            'orig_amount' => 20,
            'orig_currency_id' => $usdCurrency->id,
            'amount' => 40,
            'note' => 'only note changed',
        ]);
        $this->assertDatabaseHas('wh_receipt_products', [
            'receipt_id' => $receiptId,
            'product_id' => $this->product->id,
            'orig_unit_price' => 10,
            'orig_currency_id' => $usdCurrency->id,
            'price' => 20,
        ]);
    }

    public function test_destroy_warehouse_receipt_success(): void
    {
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/warehouse_receipts/{$receipt->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'РћРїСЂРёС…РѕРґРѕРІР°РЅРёРµ СѓРґР°Р»РµРЅРѕ']);
    }

    public function test_draft_receipt_update_recalculates_auto_transactions_and_posts_stock_on_complete(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->headers->set('X-Company-ID', (string) $this->company->id);

        $repo = app(WarehouseReceiptRepository::class);
        $defaultCurrency = Currency::firstWhere('is_default', true)
            ?? Currency::query()
                ->where('company_id', $this->company->id)
                ->where('is_default', true)
                ->firstOrFail();

        $receiptId = $repo->createItem([
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'date' => now()->toDateTimeString(),
            'note' => 'draft receipt',
            'status' => 'draft',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'price' => 10,
                ],
            ],
        ]);

        $stockBeforeComplete = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEqualsWithDelta(0.0, (float) ($stockBeforeComplete ?? 0.0), 1e-9);

        $repo->updateReceipt((int) $receiptId, [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'date' => now()->toDateTimeString(),
            'note' => 'draft receipt updated',
            'status' => 'draft',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 3,
                    'price' => 20,
                ],
            ],
        ]);

        $txs = Transaction::query()
            ->where('source_type', WhReceipt::class)
            ->where('source_id', (int) $receiptId)
            ->where('category_id', 6)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->get();
        $this->assertCount(1, $txs);
        $this->assertSame(1, $txs->where('is_debt', true)->count());
        $this->assertSame(0, $txs->where('is_debt', false)->count());
        $this->assertEqualsWithDelta(60.0, (float) $txs->where('is_debt', true)->first()->orig_amount, 0.01);
        $this->assertEquals((int) $defaultCurrency->id, (int) $txs->where('is_debt', true)->first()->currency_id);

        $repo->completeReceipt((int) $receiptId);

        $stockAfterComplete = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEqualsWithDelta(3.0, (float) ($stockAfterComplete ?? 0.0), 1e-9);

        $this->assertSame('completed', (string) WhReceipt::query()->findOrFail((int) $receiptId)->status->value);
    }

    public function test_standalone_receipt_payment_status_after_create_and_partial_payment(): void
    {
        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_receipts', [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'price' => 100.00,
                ],
            ],
        ]);
        $createResponse->assertStatus(200);

        $receiptId = (int) WhReceipt::query()->orderByDesc('id')->value('id');
        $showResponse = $this->actingAsApi($this->adminUser)->getJson("/api/warehouse_receipts/{$receiptId}");
        $showResponse->assertStatus(200);
        $showResponse->assertJsonPath('data.payment_status', 'unpaid');

        $currencyId = (int) $this->cashRegister->currency_id;
        Transaction::query()->create([
            'type' => 0,
            'creator_id' => $this->adminUser->id,
            'orig_amount' => 400,
            'amount' => 400,
            'def_amount' => 400,
            'currency_id' => $currencyId,
            'cash_id' => $this->cashRegister->id,
            'category_id' => 6,
            'client_id' => $this->client->id,
            'exchange_rate' => 1,
            'date' => now(),
            'is_debt' => false,
            'is_deleted' => false,
            'source_type' => WhReceipt::class,
            'source_id' => $receiptId,
        ]);
        CacheService::invalidateWarehouseReceiptsCache();

        $partialResponse = $this->actingAsApi($this->adminUser)->getJson("/api/warehouse_receipts/{$receiptId}");
        $partialResponse->assertStatus(200);
        $partialResponse->assertJsonPath('data.payment_status', 'partially_paid');

        $partiallyPaidIndex = $this->actingAsApi($this->adminUser)->getJson('/api/warehouse_receipts?payment_status=partially_paid');
        $partiallyPaidIndex->assertStatus(200);
        $partiallyPaidIds = collect($partiallyPaidIndex->json('data.items'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($partiallyPaidIds->contains($receiptId));

        $unpaidIndex = $this->actingAsApi($this->adminUser)->getJson('/api/warehouse_receipts?payment_status=unpaid');
        $unpaidIndex->assertStatus(200);
        $unpaidIds = collect($unpaidIndex->json('data.items'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertFalse($unpaidIds->contains($receiptId));
    }

    public function test_receipt_from_purchase_has_null_payment_status(): void
    {
        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'approved',
            'date' => now(),
            'amount' => 500,
            'orig_amount' => 500,
            'orig_currency_id' => $this->cashRegister->currency_id,
        ]);

        $receipt = WhReceipt::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'purchase_id' => $purchase->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'completed',
            'date' => now(),
            'amount' => 500,
            'orig_amount' => 500,
            'orig_currency_id' => $this->cashRegister->currency_id,
        ]);

        CacheService::invalidateWarehouseReceiptsCache();

        $showResponse = $this->actingAsApi($this->adminUser)->getJson("/api/warehouse_receipts/{$receipt->id}");
        $showResponse->assertStatus(200);
        $showResponse->assertJsonPath('data.payment_status', null);
    }
}

