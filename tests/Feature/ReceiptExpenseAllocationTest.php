<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WhReceipt;
use App\Models\WhReceiptExpenseAllocation;
use App\Models\WhReceiptProduct;
use App\Models\WhWaybill;
use App\Models\WhWaybillProduct;
use App\Services\ReceiptExpenseAllocationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReceiptExpenseAllocationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Client $client;

    protected CashRegister $cashRegister;

    protected Currency $defaultCurrency;

    protected Product $productA;

    protected Product $productB;

    protected TransactionCategory $expenseCategory;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('wh_receipt_expense_allocations')) {
            $this->markTestSkipped('Миграция wh_receipt_expense_allocations не применена.');
        }

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->defaultCurrency = $this->ensureDefaultCurrencyForCompany($this->company);
        $this->cashRegister = CashRegister::factory()->create([
            'currency_id' => $this->defaultCurrency->id,
            'company_id' => $this->company->id,
        ]);
        $this->productA = Product::factory()->create(['creator_id' => $this->adminUser->id]);
        $this->productB = Product::factory()->create(['creator_id' => $this->adminUser->id]);
        $this->expenseCategory = TransactionCategory::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => 0,
        ]);
    }

    public function test_allocates_non_goods_expense_by_line_subtotal_share(): void
    {
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'cash_id' => $this->cashRegister->id,
            'is_legacy' => true,
        ]);
        WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->productA->id,
            'quantity' => 10,
            'price' => 10,
        ]);
        WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->productB->id,
            'quantity' => 5,
            'price' => 20,
        ]);
        $lines = WhReceiptProduct::query()->where('receipt_id', $receipt->id)->orderBy('id')->get();

        $transaction = Transaction::factory()->create([
            'type' => 0,
            'is_debt' => false,
            'category_id' => $this->expenseCategory->id,
            'def_amount' => 100,
            'orig_amount' => 100,
            'amount' => 100,
            'currency_id' => $this->defaultCurrency->id,
            'cash_id' => $this->cashRegister->id,
            'source_type' => WhReceipt::class,
            'source_id' => $receipt->id,
            'is_deleted' => false,
        ]);

        app(ReceiptExpenseAllocationService::class)->syncForTransaction($transaction);

        $rows = WhReceiptExpenseAllocation::query()->where('transaction_id', $transaction->id)->get();
        $this->assertCount(2, $rows);
        $sum = (float) $rows->sum('amount_default');
        $this->assertEqualsWithDelta(100.0, $sum, 0.01);
        $byLine = $rows->keyBy('wh_receipt_product_id');
        $this->assertEqualsWithDelta(50.0, (float) $byLine[$lines[0]->id]->amount_default, 0.02);
        $this->assertEqualsWithDelta(50.0, (float) $byLine[$lines[1]->id]->amount_default, 0.02);
    }

    public function test_excludes_goods_payment_category(): void
    {
        if (! TransactionCategory::query()->whereKey(6)->exists()) {
            $this->markTestSkipped('Категория 6 (оплата товара) не найдена в БД.');
        }
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'cash_id' => $this->cashRegister->id,
            'is_legacy' => true,
        ]);
        WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $transaction = Transaction::factory()->create([
            'type' => 0,
            'is_debt' => false,
            'category_id' => 6,
            'def_amount' => 50,
            'orig_amount' => 50,
            'amount' => 50,
            'currency_id' => $this->defaultCurrency->id,
            'cash_id' => $this->cashRegister->id,
            'source_type' => WhReceipt::class,
            'source_id' => $receipt->id,
            'is_deleted' => false,
        ]);

        app(ReceiptExpenseAllocationService::class)->syncForTransaction($transaction);

        $this->assertSame(0, WhReceiptExpenseAllocation::query()->where('transaction_id', $transaction->id)->count());
    }

    public function test_allocates_by_waybill_value_when_receipt_line_has_zero_price(): void
    {
        if (! Schema::hasTable('wh_waybills')) {
            $this->markTestSkipped('Таблицы накладных не применены.');
        }
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'cash_id' => $this->cashRegister->id,
            'is_legacy' => false,
        ]);
        $rp = WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->productA->id,
            'quantity' => 100,
            'price' => 0,
        ]);
        $waybill = WhWaybill::query()->create([
            'receipt_id' => $receipt->id,
            'date' => now(),
            'number' => 'WB-1',
            'note' => null,
            'creator_id' => $this->adminUser->id,
        ]);
        WhWaybillProduct::query()->create([
            'waybill_id' => $waybill->id,
            'product_id' => $this->productA->id,
            'quantity' => '100',
            'price' => '10',
        ]);

        $transaction = Transaction::factory()->create([
            'type' => 0,
            'is_debt' => false,
            'category_id' => $this->expenseCategory->id,
            'def_amount' => 50,
            'orig_amount' => 50,
            'amount' => 50,
            'currency_id' => $this->defaultCurrency->id,
            'cash_id' => $this->cashRegister->id,
            'source_type' => WhReceipt::class,
            'source_id' => $receipt->id,
            'is_deleted' => false,
        ]);

        app(ReceiptExpenseAllocationService::class)->syncForTransaction($transaction);

        $row = WhReceiptExpenseAllocation::query()->where('transaction_id', $transaction->id)->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $rp->id, (int) $row->wh_receipt_product_id);
        $this->assertEqualsWithDelta(50.0, (float) $row->amount_default, 0.02);

        $summary = app(ReceiptExpenseAllocationService::class)->buildLandedCostSummary($receipt->fresh(['products', 'waybills.lines', 'warehouse', 'expenseAllocations', 'cashRegister.currency']));
        $this->assertEqualsWithDelta(1000.0, (float) $summary['goods_subtotal_default'], 0.05);
    }
}
