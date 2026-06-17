<?php

namespace Tests\Unit;

use App\Exceptions\CompanyContextMissingException;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Enums\JournalEntryStatus;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\WhReceipt;
use App\Services\Journal\ReceiptInventoryJournalBuilder;
use App\Services\JournalEntryService;
use App\Support\CompanyContextResolver;
use App\Support\JournalTemplateKeys;
use Database\Seeders\FinancialAccountSeeder;
use Tests\TestCase;

class JournalEntryAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new FinancialAccountSeeder)->run();
    }

    public function test_company_context_resolver_throws_when_warehouse_missing_company(): void
    {
        $this->expectException(CompanyContextMissingException::class);
        CompanyContextResolver::requireWarehouseCompanyId(null, 'test');
    }

    public function test_receipt_builder_returns_empty_lines_on_zero_landed_cost(): void
    {
        $company = Company::factory()->create();
        $receipt = WhReceipt::factory()->create([
            'amount' => 100,
        ]);
        $receipt->setRelation('warehouse', (object) ['company_id' => $company->id]);
        $receipt->setRelation('products', collect());
        $receipt->setRelation('expenseAllocations', collect());

        $builder = app(ReceiptInventoryJournalBuilder::class);

        $this->assertSame([], $builder->buildInventoryLines($receipt));
        $this->assertNull($builder->buildCostAdjustmentLines($receipt));
    }

    public function test_create_and_post_retries_existing_draft(): void
    {
        $service = app(JournalEntryService::class);
        $company = Company::factory()->create();

        $lines = [
            new \App\DTO\JournalEntryLineDraft('1000', debit: 100),
            new \App\DTO\JournalEntryLineDraft('4000', credit: 100),
        ];

        $draft = $service->createDraft(
            (int) $company->id,
            now(),
            'Draft retry',
            JournalTemplateKeys::MANUAL,
            $lines,
            Transaction::class,
            999001,
        );

        $posted = $service->createAndPost(
            (int) $company->id,
            now(),
            'Draft retry posted',
            JournalTemplateKeys::MANUAL,
            $lines,
            Transaction::class,
            999001,
        );

        $this->assertEquals($draft->id, $posted->id);
        $this->assertEquals(JournalEntryStatus::Posted, $posted->status);
        $this->assertEquals(1, JournalEntry::query()
            ->where('company_id', $company->id)
            ->where('template_key', JournalTemplateKeys::MANUAL)
            ->where('source_id', 999001)
            ->count());
    }

    public function test_financial_account_not_found_exception_on_unknown_code(): void
    {
        $service = app(JournalEntryService::class);
        $company = Company::factory()->create();

        $this->expectException(\App\Exceptions\FinancialAccountNotFoundException::class);
        $service->createDraft(
            (int) $company->id,
            now(),
            'Bad account',
            JournalTemplateKeys::MANUAL,
            [
                new \App\DTO\JournalEntryLineDraft('9999', debit: 10),
                new \App\DTO\JournalEntryLineDraft('4000', credit: 10),
            ],
        );
    }

    public function test_contra_asset_2001_backfill_sign(): void
    {
        $account = FinancialAccount::query()->where('code', '2001')->firstOrFail();
        $this->assertTrue($account->is_contra);

        $converter = app(\App\Services\MovementToJournalLineConverter::class);
        $movement = new \App\Models\FinancialAccountMovement([
            'direction' => \App\Enums\FinancialAccountMovementDirection::Increase,
            'delta' => 50,
        ]);

        $line = $converter->convert($movement, $account);
        $this->assertEquals(0.0, (float) $line->debit);
        $this->assertEquals(50.0, (float) $line->credit);
    }
}
