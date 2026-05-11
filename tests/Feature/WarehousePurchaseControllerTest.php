<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WhPurchase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehousePurchaseControllerTest extends TestCase
{
    use DatabaseTransactions;

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

        if (!Schema::hasTable('wh_purchases')) {
            $this->markTestSkipped('Таблица wh_purchases не существует.');
        }

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

        if (!DB::table('transaction_categories')->where('id', 6)->exists()) {
            DB::table('transaction_categories')->insert([
                'id' => 6,
                'name' => 'Закупка товаров',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Permission::firstOrCreate(['name' => 'warehouse_purchases_view', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'warehouse_purchases_create', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'warehouse_purchases_update', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'warehouse_purchases_delete', 'guard_name' => 'api']);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
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
        $response->assertJsonFragment(['error' => 'Редактирование доступно только для закупки в статусе Черновик']);
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
        $secondPayment->assertJsonFragment(['error' => 'Сумма оплаты не может превышать долг по закупке']);
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
        $response->assertJsonFragment(['error' => 'Ошибка оприходования: Нельзя создать оприходование из закупки в статусе Черновик']);
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
        DB::table('company_user_role')->insert([
            'company_id' => $this->company->id,
            'creator_id' => $this->regularUser->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
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
            'amount' => 100,
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
        $secondPayment->assertJsonFragment(['error' => 'Сумма оплаты не может превышать долг по закупке']);
    }
}

