<?php

namespace Tests\Feature;

use App\Enums\JournalEntryStatus;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\JournalEntryService;
use App\Services\Journal\SalaryJournalBuilder;
use App\Support\JournalTemplateKeys;
use Database\Seeders\FinancialAccountSeeder;
use Tests\TestCase;

class JournalEntryIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new FinancialAccountSeeder)->run();
    }

    public function test_create_and_post_twice_returns_single_posted_entry(): void
    {
        $service = app(JournalEntryService::class);
        $company = Company::factory()->create();

        $lines = [
            new \App\DTO\JournalEntryLineDraft('5000', debit: 200),
            new \App\DTO\JournalEntryLineDraft('3300', credit: 200),
        ];

        $tx = Transaction::factory()->create();

        $service->createAndPost(
            (int) $company->id,
            now(),
            'Salary accrual',
            JournalTemplateKeys::SALARY_ACCRUAL,
            $lines,
            Transaction::class,
            (int) $tx->id,
        );

        $service->createAndPost(
            (int) $company->id,
            now(),
            'Salary accrual',
            JournalTemplateKeys::SALARY_ACCRUAL,
            $lines,
            Transaction::class,
            (int) $tx->id,
        );

        $count = JournalEntry::query()
            ->where('company_id', $company->id)
            ->where('source_type', Transaction::class)
            ->where('source_id', $tx->id)
            ->where('template_key', JournalTemplateKeys::SALARY_ACCRUAL)
            ->where('status', JournalEntryStatus::Posted)
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_salary_builder_idempotent_via_service(): void
    {
        $company = Company::factory()->create();
        $tx = Transaction::factory()->create([
            'orig_amount' => 150,
            'def_amount' => 150,
            'is_debt' => true,
        ]);

        $builder = app(SalaryJournalBuilder::class);
        $service = app(JournalEntryService::class);
        $lines = $builder->buildLines($tx, true);

        foreach ([1, 2] as $_) {
            $service->createAndPost(
                (int) $company->id,
                now(),
                'Accrual',
                JournalTemplateKeys::SALARY_ACCRUAL,
                $lines,
                Transaction::class,
                (int) $tx->id,
            );
        }

        $this->assertEquals(1, JournalEntry::query()
            ->where('template_key', JournalTemplateKeys::SALARY_ACCRUAL)
            ->where('source_id', $tx->id)
            ->count());
    }
}
