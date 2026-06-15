<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountMovementDirection;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\FinancialAccountRule;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Repositories\TransactionsRepository;
use App\Repositories\WarehouseReceiptRepository;
use App\Services\FinancialAccountService;
use App\Services\FinancialAccountVerifierService;
use App\Support\ResolvedCompany;
use App\Support\TransactionCategoryBindingKeys;
use Database\Seeders\FinancialAccountRuleSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Concerns\SeedsWarehouseTransactionCategoryBindings;
use Tests\TestCase;

class FinancialAccountMovementsTest extends TestCase
{
    use SeedsWarehouseTransactionCategoryBindings;

    protected User $adminUser;

    protected Company $company;

    protected Client $client;

    protected Warehouse $warehouse;

    protected Product $product;

    protected CashRegister $cashRegister;

    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->currency = $this->ensureDefaultCurrencyForCompany($this->company);
        if (! Currency::query()->where('company_id', $this->company->id)->where('is_report', true)->exists()) {
            Currency::factory()->create([
                'company_id' => $this->company->id,
                'is_default' => false,
                'is_report' => true,
            ]);
        }
        $this->seedWarehouseGoodsPaymentBindings($this->company, $this->adminUser);
        $this->seedWarehouseDeliveryExpenseBinding($this->company, $this->adminUser);
        (new FinancialAccountRuleSeeder)->run();

        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
            'balance' => 100000,
            'is_working_minus' => true,
        ]);
        $this->product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
        ]);

        $this->bindCompanyContext();
    }

    protected function bindCompanyContext(): void
    {
        request()->attributes->set(ResolvedCompany::ATTRIBUTE, (int) $this->company->id);
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    public function test_sale_debt_creates_customers_increase_movement(): void
    {
        $response = $this->actingAsApi($this->adminUser)->postJson('/api/sales', [
            'client_id' => $this->client->id,
            'type' => 'balance',
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'price' => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $saleId = (int) Sale::query()->orderByDesc('id')->value('id');
        $transactionIds = $this->saleTransactionIds($saleId);
        $customersAccount = $this->accountByCode('1200');

        $this->assertEquals(
            1,
            FinancialAccountMovement::query()
                ->active()
                ->whereIn('transaction_id', $transactionIds)
                ->where('financial_account_id', $customersAccount->id)
                ->where('direction', FinancialAccountMovementDirection::Increase->value)
                ->count()
        );
    }

    public function test_sale_cash_creates_customers_and_cash_movements(): void
    {
        $response = $this->actingAsApi($this->adminUser)->postJson('/api/sales', [
            'client_id' => $this->client->id,
            'type' => 'cash',
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 200.00,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $saleId = (int) Sale::query()->orderByDesc('id')->value('id');
        $transactionIds = $this->saleTransactionIds($saleId);
        $customersAccount = $this->accountByCode('1200');
        $cashAccount = $this->accountByCode('1000');

        $this->assertCount(2, $transactionIds);
        $this->assertEquals(
            2,
            FinancialAccountMovement::query()
                ->active()
                ->whereIn('transaction_id', $transactionIds)
                ->whereIn('financial_account_id', [$customersAccount->id, $cashAccount->id])
                ->count()
        );
    }

    public function test_receipt_debt_creates_suppliers_increase(): void
    {
        $response = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_receipts', [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 3,
                    'price' => 50.00,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $receiptId = (int) WhReceipt::query()->orderByDesc('id')->value('id');
        $transactionIds = $this->receiptTransactionIds($receiptId);
        $suppliersAccount = $this->accountByCode('3200');

        $this->assertEquals(
            1,
            FinancialAccountMovement::query()
                ->active()
                ->whereIn('transaction_id', $transactionIds)
                ->where('financial_account_id', $suppliersAccount->id)
                ->where('direction', FinancialAccountMovementDirection::Increase->value)
                ->count()
        );
    }

    public function test_receipt_payment_creates_cash_and_suppliers_decrease_movements(): void
    {
        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/warehouse_receipts', [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'price' => 100.00,
                ],
            ],
        ]);
        $createResponse->assertStatus(200);
        $receiptId = (int) WhReceipt::query()->orderByDesc('id')->value('id');
        $this->approveReceipt($receiptId);

        $transactionId = (int) app(TransactionsRepository::class)->createItem(
            $this->receiptPaymentPayload($receiptId, 200),
            true
        );

        $cashAccount = $this->accountByCode('1000');
        $suppliersAccount = $this->accountByCode('3200');

        $movements = FinancialAccountMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->get();

        $this->assertCount(2, $movements);
        $this->assertTrue($movements->contains(fn ($m) => $m->financial_account_id === $cashAccount->id && $m->direction === FinancialAccountMovementDirection::Decrease));
        $this->assertTrue($movements->contains(fn ($m) => $m->financial_account_id === $suppliersAccount->id && $m->direction === FinancialAccountMovementDirection::Decrease));
    }

    public function test_sync_transaction_is_idempotent(): void
    {
        $repository = app(TransactionsRepository::class);
        $service = app(FinancialAccountService::class);

        $transactionId = (int) $repository->createItem(
            $this->orderDebtTransactionPayload([
                'orig_amount' => 150,
                'source_type' => 'App\Models\Order',
                'source_id' => 1,
            ]),
            true
        );

        $tx = Transaction::query()->findOrFail($transactionId);
        $service->syncTransaction($tx);
        $service->syncTransaction($tx);

        $this->assertEquals(
            1,
            FinancialAccountMovement::query()->active()->where('transaction_id', $transactionId)->count()
        );
    }

    public function test_delete_transaction_soft_deletes_movements(): void
    {
        $repository = app(TransactionsRepository::class);

        $transactionId = (int) $repository->createItem(
            $this->orderDebtTransactionPayload(['orig_amount' => 300]),
            true
        );

        $this->assertGreaterThan(0, FinancialAccountMovement::query()->active()->where('transaction_id', $transactionId)->count());

        $repository->deleteItem($transactionId);

        $this->assertEquals(
            0,
            FinancialAccountMovement::query()->active()->where('transaction_id', $transactionId)->count()
        );
    }

    public function test_verifier_passes_for_synced_transaction(): void
    {
        $repository = app(TransactionsRepository::class);
        $verifier = app(FinancialAccountVerifierService::class);

        $transactionId = (int) $repository->createItem(
            $this->orderDebtTransactionPayload(['orig_amount' => 120]),
            true
        );

        $result = $verifier->verifyTransaction($transactionId);
        $this->assertTrue($result->passed, implode('; ', $result->errors));
    }

    public function test_financial_accounts_api_index(): void
    {
        $response = $this->actingAsApi($this->adminUser)->getJson('/api/financial/accounts');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'items' => [
                    ['code', 'name', 'type', 'balance', 'turnover'],
                ],
            ],
        ]);
    }

    public function test_financial_accounts_history_is_grouped_by_transaction(): void
    {
        $repository = app(TransactionsRepository::class);
        $repository->createItem(
            $this->receiptPaymentPayload(null, 100, withoutSource: true),
            true
        );

        $account = $this->accountByCode('1000');
        $response = $this->actingAsApi($this->adminUser)->getJson("/api/financial/accounts/{$account->id}/history");
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'items' => [
                    ['transaction_id', 'transaction_date', 'movements'],
                ],
            ],
        ]);
        $this->assertGreaterThanOrEqual(1, count($response->json('data.items.0.movements') ?? []));
    }

    public function test_backfill_command_strict_skips_existing(): void
    {
        $repository = app(TransactionsRepository::class);
        $transactionId = (int) $repository->createItem(
            $this->orderDebtTransactionPayload(['orig_amount' => 50]),
            true
        );

        $before = FinancialAccountMovement::query()->active()->where('transaction_id', $transactionId)->count();

        Artisan::call('financial:sync-existing-transactions', [
            '--transaction-id' => (string) $transactionId,
            '--mode' => 'strict',
        ]);

        $after = FinancialAccountMovement::query()->active()->where('transaction_id', $transactionId)->count();
        $this->assertEquals($before, $after);
    }

    public function test_rule_seeder_is_idempotent_for_order_debt_rule(): void
    {
        $before = FinancialAccountRule::query()
            ->where('binding_key', TransactionCategoryBindingKeys::ORDER)
            ->where('type', 1)
            ->where('is_debt', true)
            ->count();

        (new FinancialAccountRuleSeeder)->run();

        $after = FinancialAccountRule::query()
            ->where('binding_key', TransactionCategoryBindingKeys::ORDER)
            ->where('type', 1)
            ->where('is_debt', true)
            ->count();

        $this->assertEquals($before, $after);
        $this->assertGreaterThanOrEqual(1, $after);
    }

    public function test_movement_has_delta_and_balance_after_after_sync(): void
    {
        $repository = app(TransactionsRepository::class);
        $transactionId = (int) $repository->createItem(
            $this->orderDebtTransactionPayload(['orig_amount' => 175]),
            true
        );

        $movement = FinancialAccountMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->firstOrFail();

        $this->assertEquals(175.0, (float) $movement->delta);
        $this->assertEquals(175.0, (float) $movement->balance_after);
    }

    public function test_financial_balance_at_endpoint(): void
    {
        $repository = app(TransactionsRepository::class);
        $repository->createItem(
            $this->orderDebtTransactionPayload(['orig_amount' => 90]),
            true
        );

        $account = $this->accountByCode('1200');
        $response = $this->actingAsApi($this->adminUser)->getJson(
            "/api/financial/accounts/{$account->id}/balance-at?date=".now()->toDateString()
        );

        $response->assertStatus(200);
        $this->assertEquals(90.0, (float) $response->json('data.balance'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderDebtTransactionPayload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $this->transactionCategoryBindingsByKey[TransactionCategoryBindingKeys::ORDER]->id,
            'type' => 1,
            'is_debt' => true,
            'orig_amount' => 100,
            'currency_id' => $this->currency->id,
            'date' => now()->toDateTimeString(),
            'creator_id' => $this->adminUser->id,
            'project_id' => null,
            'source_type' => null,
            'source_id' => null,
            'exchange_rate' => 1,
        ], $overrides);
    }

    /**
     * @param  int|null  $receiptId
     * @param  float  $amount
     * @param  bool  $withoutSource
     * @return array<string, mixed>
     */
    private function receiptPaymentPayload(?int $receiptId, float $amount, bool $withoutSource = false): array
    {
        return [
            'type' => 0,
            'creator_id' => $this->adminUser->id,
            'orig_amount' => $amount,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $this->transactionCategoryBindingsByKey[TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT]->id,
            'client_id' => $this->client->id,
            'project_id' => null,
            'exchange_rate' => 1,
            'date' => now()->toDateTimeString(),
            'is_debt' => false,
            'source_type' => $withoutSource ? null : WhReceipt::class,
            'source_id' => $withoutSource ? null : $receiptId,
        ];
    }

    /**
     * @param  int  $receiptId
     * @return void
     */
    private function approveReceipt(int $receiptId): void
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

    /**
     * @param  int  $saleId
     * @return array<int, int>
     */
    private function saleTransactionIds(int $saleId): array
    {
        return Transaction::query()
            ->where('source_type', Sale::class)
            ->where('source_id', $saleId)
            ->where('is_deleted', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  int  $receiptId
     * @return array<int, int>
     */
    private function receiptTransactionIds(int $receiptId): array
    {
        return Transaction::query()
            ->where('source_type', WhReceipt::class)
            ->where('source_id', $receiptId)
            ->where('is_deleted', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  string  $code
     * @return FinancialAccount
     */
    private function accountByCode(string $code): FinancialAccount
    {
        return FinancialAccount::query()->where('code', $code)->firstOrFail();
    }
}
