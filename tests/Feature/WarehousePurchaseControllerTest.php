<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Models\WhPurchaseProduct;
use App\Repositories\WarehouseReceiptRepository;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Concerns\SeedsWarehouseTransactionCategoryBindings;
use Tests\TestCase;

class WarehousePurchaseControllerTest extends TestCase
{
    use SeedsWarehouseTransactionCategoryBindings;

    protected User $adminUser;
    protected User $regularUser;
    protected Company $company;
    protected Client $supplier;
    protected Product $product;
    protected Warehouse $warehouse;
    protected CashRegister $cashRegister;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('broadcasting.default', 'log');


        $this->company = Company::factory()->create();

        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);

        $this->regularUser = User::factory()->create([
            'is_admin' => false,
            'is_active' => true,
        ]);
        $this->regularUser->companies()->attach($this->company->id);

        $this->supplier = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
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

        if (!Currency::query()->where('company_id', $this->company->id)->where('is_report', true)->exists()) {
            Currency::factory()->create([
                'company_id' => $this->company->id,
                'is_default' => false,
                'is_report' => true,
            ]);
        }

        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $currency->id,
            'balance' => 100000,
            'is_working_minus' => true,
        ]);

        $this->seedWarehouseGoodsPaymentBindings($this->company, $this->adminUser);

        Permission::firstOrCreate(['name' => 'warehouse_purchases_view', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'warehouse_purchases_create', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'warehouse_purchases_update', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'warehouse_purchases_delete', 'guard_name' => 'api']);
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    private function createApprovedPurchaseWithProduct(float $quantity, float $unitPrice): WhPurchase
    {
        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'approved',
            'date' => now(),
            'amount' => $quantity * $unitPrice,
            'orig_amount' => $quantity * $unitPrice,
            'orig_currency_id' => $this->cashRegister->currency_id,
        ]);
        WhPurchaseProduct::query()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'price' => $unitPrice,
            'orig_unit_price' => $unitPrice,
            'orig_currency_id' => $this->cashRegister->currency_id,
        ]);

        return $purchase;
    }

    private function payPurchaseInFull(int $purchaseId, float $amount): void
    {
        $this->actingAsApi($this->adminUser)->postJson("/api/warehouse_purchases/{$purchaseId}/pay", [
            'cash_id' => $this->cashRegister->id,
            'amount' => $amount,
        ])->assertStatus(200);
    }

    public function test_store_warehouse_purchase_success_and_creates_debt_transaction(): void
    {
        $payload = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'price' => 10,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', $payload);
        $response->assertStatus(200);
        $purchaseId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $purchaseId);

        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchaseId,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'status' => 'draft',
            'amount' => 50,
        ]);

        $this->assertDatabaseHas('transactions', [
            'source_type' => \App\Models\WhPurchase::class,
            'source_id' => $purchaseId,
            'is_debt' => true,
            'orig_amount' => 50,
        ]);
    }

    public function test_store_warehouse_purchase_rejects_invalid_nested_products_payload(): void
    {
        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'price' => 10,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['products.0.quantity']);
    }

    public function test_store_warehouse_purchase_rejects_nested_product_with_invalid_price(): void
    {
        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 'invalid',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['products.0.price']);
    }

    public function test_store_warehouse_purchase_rejects_empty_products_array(): void
    {
        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['products']);
    }

    public function test_destroy_draft_purchase_soft_deletes_linked_transactions(): void
    {
        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'price' => 15,
                ],
            ],
        ]);
        $createResponse->assertStatus(200);
        $purchaseId = (int) $createResponse->json('data.id');

        $txId = (int) Transaction::query()
            ->where('source_type', WhPurchase::class)
            ->where('source_id', $purchaseId)
            ->where('is_deleted', false)
            ->value('id');
        $this->assertGreaterThan(0, $txId);

        $deleteResponse = $this->actingAsApi($this->adminUser)->deleteJson("/api/warehouse_purchases/{$purchaseId}");
        $deleteResponse->assertStatus(200);

        $this->assertDatabaseMissing('wh_purchases', ['id' => $purchaseId]);
        $this->assertDatabaseHas('transactions', [
            'id' => $txId,
            'is_deleted' => true,
        ]);
    }

    public function test_update_purchase_forbidden_when_not_draft(): void
    {
        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'approved',
            'date' => now(),
            'amount' => 10,
        ]);

        $response = $this->actingAsApi($this->adminUser)->putJson("/api/warehouse_purchases/{$purchase->id}", [
            'note' => 'updated',
            'cash_id' => $this->cashRegister->id,
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => __('warehouse_purchase.edit_only_draft')]);
    }

    public function test_manual_purchase_completion_via_api_is_forbidden(): void
    {
        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'approved',
            'date' => now(),
            'amount' => 10,
        ]);

        $response = $this->actingAsApi($this->adminUser)->putJson("/api/warehouse_purchases/{$purchase->id}", [
            'status' => 'completed',
            'cash_id' => $this->cashRegister->id,
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => __('warehouse_purchase.completion_is_automatic')]);
        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchase->id,
            'status' => 'approved',
        ]);
    }

    public function test_purchase_stays_approved_when_receipts_done_but_unpaid(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $purchase = $this->createApprovedPurchaseWithProduct(5, 10.0);
        $receiptRepo = app(WarehouseReceiptRepository::class);
        $receiptId = $receiptRepo->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'creator_id' => $this->adminUser->id,
            'date' => now()->toDateTimeString(),
            'note' => null,
            'status' => 'draft',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'price' => 10,
                ],
            ],
        ]);
        $this->approveReceiptForCompletion((int) $receiptId);

        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchase->id,
            'status' => 'approved',
        ]);
    }

    public function test_purchase_auto_completes_when_receipts_done_and_fully_paid(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $purchase = $this->createApprovedPurchaseWithProduct(5, 10.0);
        $receiptRepo = app(WarehouseReceiptRepository::class);
        $receiptId = $receiptRepo->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'creator_id' => $this->adminUser->id,
            'date' => now()->toDateTimeString(),
            'note' => null,
            'status' => 'draft',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'price' => 10,
                ],
            ],
        ]);
        $this->approveReceiptForCompletion((int) $receiptId);

        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchase->id,
            'status' => 'approved',
        ]);

        $this->payPurchaseInFull($purchase->id, 50.0);

        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchase->id,
            'status' => 'completed',
        ]);
    }

    public function test_purchase_reverts_to_approved_when_completed_receipt_is_deleted(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'approved',
            'date' => now(),
            'amount' => 50,
            'orig_amount' => 50,
            'orig_currency_id' => $this->cashRegister->currency_id,
        ]);
        WhPurchaseProduct::query()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'price' => 10,
            'orig_unit_price' => 10,
            'orig_currency_id' => $this->cashRegister->currency_id,
        ]);

        $receiptRepo = app(WarehouseReceiptRepository::class);
        $receiptId = $receiptRepo->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'creator_id' => $this->adminUser->id,
            'date' => now()->toDateTimeString(),
            'note' => null,
            'status' => 'draft',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'price' => 10,
                ],
            ],
        ]);
        $this->approveReceiptForCompletion((int) $receiptId);
        $this->payPurchaseInFull($purchase->id, 50.0);

        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchase->id,
            'status' => 'completed',
        ]);

        $receiptRepo->deleteItem((int) $receiptId);

        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchase->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseMissing('wh_receipts', ['id' => $receiptId]);
    }

    public function test_receipt_from_purchase_rejects_product_not_in_purchase(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $purchase = $this->createApprovedPurchaseWithProduct(5, 10.0);
        $otherProduct = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) __('warehouse_purchase.receipt_product_not_in_purchase'));

        app(WarehouseReceiptRepository::class)->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'creator_id' => $this->adminUser->id,
            'date' => now()->toDateTimeString(),
            'note' => null,
            'status' => 'draft',
            'products' => [
                [
                    'product_id' => $otherProduct->id,
                    'quantity' => 1,
                    'price' => 10,
                ],
            ],
        ]);
    }

    public function test_purchase_stays_approved_until_all_partial_receipts_are_completed(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'approved',
            'date' => now(),
            'amount' => 50,
            'orig_amount' => 50,
            'orig_currency_id' => $this->cashRegister->currency_id,
        ]);
        WhPurchaseProduct::query()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'price' => 5,
            'orig_unit_price' => 5,
            'orig_currency_id' => $this->cashRegister->currency_id,
        ]);

        $receiptRepo = app(WarehouseReceiptRepository::class);
        $firstReceiptId = $receiptRepo->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'creator_id' => $this->adminUser->id,
            'date' => now()->toDateTimeString(),
            'note' => null,
            'status' => 'draft',
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 4, 'price' => 5],
            ],
        ]);
        $this->approveReceiptForCompletion((int) $firstReceiptId);

        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchase->id,
            'status' => 'approved',
        ]);

        $secondReceiptId = $receiptRepo->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'creator_id' => $this->adminUser->id,
            'date' => now()->toDateTimeString(),
            'note' => null,
            'status' => 'draft',
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 6, 'price' => 5],
            ],
        ]);
        $this->approveReceiptForCompletion((int) $secondReceiptId);
        $this->payPurchaseInFull($purchase->id, 50.0);

        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchase->id,
            'status' => 'completed',
        ]);
    }

    public function test_purchase_payment_rejects_overpayment(): void
    {
        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'price' => 10,
                ],
            ],
        ]);
        $createResponse->assertStatus(200);
        $purchaseId = (int) $createResponse->json('data.id');

        $firstPayment = $this->actingAsApi($this->adminUser)->postJson("/api/warehouse_purchases/{$purchaseId}/pay", [
            'cash_id' => $this->cashRegister->id,
            'amount' => 70,
        ]);
        $firstPayment->assertStatus(200);

        $secondPayment = $this->actingAsApi($this->adminUser)->postJson("/api/warehouse_purchases/{$purchaseId}/pay", [
            'cash_id' => $this->cashRegister->id,
            'amount' => 40,
        ]);
        $secondPayment->assertStatus(400);
        $secondPayment->assertJsonFragment(['error' => __('warehouse_purchase.goods_payment_exceeds_remaining')]);
    }

    public function test_receipt_creation_from_draft_purchase_is_forbidden(): void
    {
        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'draft',
            'date' => now(),
            'amount' => 50,
        ]);

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_receipts', [
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 50,
                ],
            ],
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment([
            'error' => __('warehouse_receipt.store_error', [
                'message' => __('api.warehouse_receipt.from_draft_purchase_forbidden'),
            ]),
        ]);
    }

    public function test_purchase_index_forbidden_without_view_permission_for_non_admin(): void
    {
        $response = $this->actingAsApi($this->regularUser)->getJson('/api/warehouse_purchases');
        $response->assertStatus(403);
    }

    public function test_purchase_index_allowed_with_view_permission_for_non_admin(): void
    {
        $role = Role::query()->create([
            'name' => 'warehouse_purchase_view_role_'.uniqid('', true),
            'guard_name' => 'api',
        ]);
        $role->givePermissionTo('warehouse_purchases_view');
        $this->regularUser->companyRoles()->syncWithoutDetaching([
            $role->id => ['company_id' => $this->company->id],
        ]);

        $response = $this->actingAsApi($this->regularUser)->getJson('/api/warehouse_purchases');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'items',
                'meta' => ['current_page', 'next_page', 'last_page', 'per_page', 'total'],
            ],
        ]);
    }

    public function test_store_purchase_converts_document_currency_to_default_amount(): void
    {
        $defaultCurrency = Currency::query()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
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

        $payload = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $usdCurrency->id,
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'price' => 10],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', $payload);
        $response->assertStatus(200);
        $purchaseId = (int) $response->json('data.id');

        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchaseId,
            'currency_id' => $usdCurrency->id,
            'orig_amount' => 50,
            'orig_currency_id' => $usdCurrency->id,
            'amount' => 100,
        ]);
        $this->assertDatabaseHas('wh_purchase_products', [
            'purchase_id' => $purchaseId,
            'product_id' => $this->product->id,
            'orig_unit_price' => 10,
            'orig_currency_id' => $usdCurrency->id,
            'price' => 20,
        ]);
        $this->assertDatabaseHas('transactions', [
            'source_type' => \App\Models\WhPurchase::class,
            'source_id' => $purchaseId,
            'is_debt' => true,
            'currency_id' => $usdCurrency->id,
            'orig_amount' => 50,
            'def_amount' => 100,
        ]);
    }

    public function test_purchase_payment_rejects_overpayment_in_foreign_currency(): void
    {
        $defaultCurrency = Currency::query()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
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

        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $usdCurrency->id,
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'price' => 10],
            ],
        ]);
        $createResponse->assertStatus(200);
        $purchaseId = (int) $createResponse->json('data.id');

        $firstPayment = $this->actingAsApi($this->adminUser)->postJson("/api/warehouse_purchases/{$purchaseId}/pay", [
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $usdCurrency->id,
            'amount' => 60,
        ]);
        $firstPayment->assertStatus(200);

        $secondPayment = $this->actingAsApi($this->adminUser)->postJson("/api/warehouse_purchases/{$purchaseId}/pay", [
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $usdCurrency->id,
            'amount' => 50,
        ]);
        $secondPayment->assertStatus(400);
        $secondPayment->assertJsonFragment(['error' => __('warehouse_purchase.goods_payment_exceeds_remaining')]);
    }

    public function test_update_draft_merges_duplicate_product_lines_in_payload(): void
    {
        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'price' => 10,
                ],
            ],
        ]);
        $createResponse->assertStatus(200);
        $purchaseId = (int) $createResponse->json('data.id');

        $updateResponse = $this->actingAsApi($this->adminUser)->putJson("/api/warehouse_purchases/{$purchaseId}", [
            'cash_id' => $this->cashRegister->id,
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'price' => 10],
                ['product_id' => $this->product->id, 'quantity' => 3, 'price' => 10],
            ],
        ]);
        $updateResponse->assertStatus(200);

        $this->assertSame(1, WhPurchaseProduct::query()->where('purchase_id', $purchaseId)->count());
        $this->assertDatabaseHas('wh_purchase_products', [
            'purchase_id' => $purchaseId,
            'product_id' => $this->product->id,
            'quantity' => 5,
        ]);
    }

    public function test_update_draft_syncs_products_and_debt(): void
    {
        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'price' => 10,
                ],
            ],
        ]);
        $createResponse->assertStatus(200);
        $purchaseId = (int) $createResponse->json('data.id');

        $updateResponse = $this->actingAsApi($this->adminUser)->putJson("/api/warehouse_purchases/{$purchaseId}", [
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'price' => 12,
                ],
            ],
        ]);
        $updateResponse->assertStatus(200);

        $this->assertDatabaseHas('wh_purchases', [
            'id' => $purchaseId,
            'amount' => 60,
            'orig_amount' => 60,
        ]);
        $this->assertDatabaseHas('wh_purchase_products', [
            'purchase_id' => $purchaseId,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'orig_unit_price' => 12,
            'price' => 12,
        ]);
        $this->assertDatabaseHas('transactions', [
            'source_type' => WhPurchase::class,
            'source_id' => $purchaseId,
            'is_debt' => true,
            'orig_amount' => 60,
            'amount' => 60,
        ]);
    }

    public function test_get_after_update_returns_fresh_products(): void
    {
        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
        ]);
        $createResponse->assertStatus(200);
        $purchaseId = (int) $createResponse->json('data.id');

        $this->actingAsApi($this->adminUser)->putJson("/api/warehouse_purchases/{$purchaseId}", [
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 3,
                    'price' => 25,
                ],
            ],
        ])->assertStatus(200);

        $showResponse = $this->actingAsApi($this->adminUser)->getJson("/api/warehouse_purchases/{$purchaseId}");
        $showResponse->assertStatus(200);
        $products = $showResponse->json('data.products');
        $this->assertCount(1, $products);
        $this->assertEquals(3, (float) $products[0]['quantity']);
        $this->assertEquals(25, (float) $products[0]['orig_unit_price']);
        $this->assertEquals(75, (float) $showResponse->json('data.orig_amount'));
    }

    public function test_purchase_payment_status_lifecycle_and_unpaid_filter(): void
    {
        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_purchases', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'price' => 10,
                ],
            ],
        ]);
        $createResponse->assertStatus(200);
        $purchaseId = (int) $createResponse->json('data.id');

        $showResponse = $this->actingAsApi($this->adminUser)->getJson("/api/warehouse_purchases/{$purchaseId}");
        $showResponse->assertStatus(200);
        $showResponse->assertJsonPath('data.payment_status', 'unpaid');
        $this->assertEqualsWithDelta(0.0, (float) $showResponse->json('data.paid_amount'), 1e-9);

        $unpaidIndex = $this->actingAsApi($this->adminUser)->getJson('/api/warehouse_purchases?payment_status=unpaid');
        $unpaidIndex->assertStatus(200);
        $unpaidIds = collect($unpaidIndex->json('data.items'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($unpaidIds->contains($purchaseId));

        $this->actingAsApi($this->adminUser)->postJson("/api/warehouse_purchases/{$purchaseId}/pay", [
            'cash_id' => $this->cashRegister->id,
            'amount' => 40,
        ])->assertStatus(200);

        $partialResponse = $this->actingAsApi($this->adminUser)->getJson("/api/warehouse_purchases/{$purchaseId}");
        $partialResponse->assertStatus(200);
        $partialResponse->assertJsonPath('data.payment_status', 'partially_paid');
        $this->assertEqualsWithDelta(40.0, (float) WhPurchase::query()->findOrFail($purchaseId)->paid_amount, 1e-9);

        $this->actingAsApi($this->adminUser)->postJson("/api/warehouse_purchases/{$purchaseId}/pay", [
            'cash_id' => $this->cashRegister->id,
            'amount' => 60,
        ])->assertStatus(200);

        $paidResponse = $this->actingAsApi($this->adminUser)->getJson("/api/warehouse_purchases/{$purchaseId}");
        $paidResponse->assertStatus(200);
        $paidResponse->assertJsonPath('data.payment_status', 'paid');
        $this->assertEqualsWithDelta(100.0, (float) WhPurchase::query()->findOrFail($purchaseId)->paid_amount, 1e-9);

        $paidIndex = $this->actingAsApi($this->adminUser)->getJson('/api/warehouse_purchases?payment_status=paid');
        $paidIndex->assertStatus(200);
        $paidIds = collect($paidIndex->json('data.items'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($paidIds->contains($purchaseId));

        $unpaidAfter = $this->actingAsApi($this->adminUser)->getJson('/api/warehouse_purchases?payment_status=unpaid');
        $unpaidAfter->assertStatus(200);
        $unpaidAfterIds = collect($unpaidAfter->json('data.items'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertFalse($unpaidAfterIds->contains($purchaseId));
    }

    public function test_destroy_approved_purchase_without_receipts(): void
    {
        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'approved',
            'date' => now(),
            'amount' => 20,
            'orig_amount' => 20,
            'orig_currency_id' => $this->cashRegister->currency_id,
        ]);

        $response = $this->actingAsApi($this->adminUser)->deleteJson("/api/warehouse_purchases/{$purchase->id}");
        $response->assertStatus(200);
        $this->assertDatabaseMissing('wh_purchases', ['id' => $purchase->id]);
    }

    public function test_destroy_purchase_cascades_completed_receipt_and_reverses_stock(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $purchase = $this->createApprovedPurchaseWithProduct(5, 10.0);
        $receiptRepo = app(WarehouseReceiptRepository::class);
        $receiptId = $receiptRepo->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'date' => now()->toDateTimeString(),
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'price' => 10,
                ],
            ],
        ]);
        $this->approveReceiptForCompletion((int) $receiptId);

        $this->assertEqualsWithDelta(
            5.0,
            (float) WarehouseStock::query()
                ->where('warehouse_id', $this->warehouse->id)
                ->where('product_id', $this->product->id)
                ->value('quantity'),
            1e-9
        );

        $response = $this->actingAsApi($this->adminUser)->deleteJson("/api/warehouse_purchases/{$purchase->id}");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('wh_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('wh_receipts', ['id' => $receiptId]);
        $this->assertEqualsWithDelta(
            0.0,
            (float) (WarehouseStock::query()
                ->where('warehouse_id', $this->warehouse->id)
                ->where('product_id', $this->product->id)
                ->value('quantity') ?? 0),
            1e-9
        );
    }

    public function test_destroy_purchase_fails_when_completed_receipt_stock_would_go_negative(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $purchase = $this->createApprovedPurchaseWithProduct(5, 10.0);
        $receiptRepo = app(WarehouseReceiptRepository::class);
        $receiptId = $receiptRepo->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'date' => now()->toDateTimeString(),
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'price' => 10,
                ],
            ],
        ]);
        $this->approveReceiptForCompletion((int) $receiptId);

        WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 2]);

        $response = $this->actingAsApi($this->adminUser)->deleteJson("/api/warehouse_purchases/{$purchase->id}");
        $response->assertStatus(400);

        $this->assertDatabaseHas('wh_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('wh_receipts', ['id' => $receiptId]);
    }

    public function test_destroy_purchase_cascades_multiple_receipts_in_all_statuses(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $purchase = $this->createApprovedPurchaseWithProduct(10, 5.0);
        $receiptRepo = app(WarehouseReceiptRepository::class);

        $draftReceiptId = $receiptRepo->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'date' => now()->toDateTimeString(),
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'price' => 5],
            ],
        ]);

        $approvedReceiptId = $receiptRepo->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'date' => now()->toDateTimeString(),
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 3, 'price' => 5],
            ],
        ]);
        $this->approveReceiptForCompletion((int) $approvedReceiptId);

        $completedReceiptId = $receiptRepo->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'date' => now()->toDateTimeString(),
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'price' => 5],
            ],
        ]);
        $this->approveReceiptForCompletion((int) $completedReceiptId);

        $this->assertEqualsWithDelta(
            5.0,
            (float) WarehouseStock::query()
                ->where('warehouse_id', $this->warehouse->id)
                ->where('product_id', $this->product->id)
                ->value('quantity'),
            1e-9
        );

        $response = $this->actingAsApi($this->adminUser)->deleteJson("/api/warehouse_purchases/{$purchase->id}");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('wh_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('wh_receipts', ['id' => $draftReceiptId]);
        $this->assertDatabaseMissing('wh_receipts', ['id' => $approvedReceiptId]);
        $this->assertDatabaseMissing('wh_receipts', ['id' => $completedReceiptId]);
        $this->assertEqualsWithDelta(
            0.0,
            (float) (WarehouseStock::query()
                ->where('warehouse_id', $this->warehouse->id)
                ->where('product_id', $this->product->id)
                ->value('quantity') ?? 0),
            1e-9
        );
    }

    public function test_destroy_purchase_forbidden_without_receipt_delete_permission(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->regularUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        Permission::firstOrCreate(['name' => 'warehouse_receipts_delete_all', 'guard_name' => 'api']);
        $role = Role::query()->create([
            'name' => 'warehouse_purchase_delete_only_'.uniqid('', true),
            'guard_name' => 'api',
        ]);
        $role->givePermissionTo(['warehouse_purchases_delete']);
        $this->regularUser->companyRoles()->syncWithoutDetaching([
            $role->id => ['company_id' => $this->company->id],
        ]);

        $purchase = $this->createApprovedPurchaseWithProduct(5, 10.0);
        $receiptId = app(WarehouseReceiptRepository::class)->createItem([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_id' => $purchase->id,
            'creator_id' => $this->adminUser->id,
            'date' => now()->toDateTimeString(),
            'products' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'price' => 10],
            ],
        ]);

        $response = $this->actingAsApi($this->regularUser)->deleteJson("/api/warehouse_purchases/{$purchase->id}");
        $response->assertStatus(403);

        $this->assertDatabaseHas('wh_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('wh_receipts', ['id' => $receiptId]);
    }

    public function test_destroy_purchase_fails_when_cash_register_would_go_negative(): void
    {
        auth()->shouldUse('api');
        auth('api')->setUser($this->adminUser);
        request()->attributes->set(\App\Support\ResolvedCompany::ATTRIBUTE, (int) $this->company->id);

        $this->cashRegister->update([
            'balance' => 30,
            'is_working_minus' => false,
        ]);

        $purchase = WhPurchase::query()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'creator_id' => $this->adminUser->id,
            'status' => 'approved',
            'date' => now(),
            'amount' => 50,
            'orig_amount' => 50,
            'orig_currency_id' => $this->cashRegister->currency_id,
        ]);

        Transaction::query()->create([
            'type' => 1,
            'creator_id' => $this->adminUser->id,
            'orig_amount' => 50,
            'amount' => 50,
            'def_amount' => 50,
            'currency_id' => $this->cashRegister->currency_id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $this->warehouseGoodsPaymentCategory->id,
            'client_id' => $this->supplier->id,
            'exchange_rate' => 1,
            'date' => now(),
            'is_debt' => false,
            'is_deleted' => false,
            'source_type' => WhPurchase::class,
            'source_id' => $purchase->id,
        ]);

        CashRegister::query()
            ->where('id', $this->cashRegister->id)
            ->update(['balance' => 30, 'is_working_minus' => false]);

        $response = $this->actingAsApi($this->adminUser)->deleteJson("/api/warehouse_purchases/{$purchase->id}");
        $response->assertStatus(400);

        $this->assertDatabaseHas('wh_purchases', ['id' => $purchase->id]);
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
}

