<?php

namespace Tests\Unit;

use App\DTO\JournalEntryLineDraft;
use App\Enums\FinancialAccountMovementDirection;
use App\Enums\FinancialAccountType;
use App\Enums\JournalEntryStatus;
use App\Models\FinancialAccount;
use App\Services\JournalEntryService;
use App\Services\MovementToJournalLineConverter;
use App\Support\JournalTemplateKeys;
use Database\Seeders\FinancialAccountSeeder;
use Tests\TestCase;

class JournalEntryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new FinancialAccountSeeder)->run();
    }

    public function test_create_and_post_balanced_entry(): void
    {
        $service = app(JournalEntryService::class);
        $entry = $service->createAndPost(
            1,
            now(),
            'Test entry',
            JournalTemplateKeys::MANUAL,
            [
                new JournalEntryLineDraft('1000', debit: 100),
                new JournalEntryLineDraft('4000', credit: 100),
            ],
        );

        $this->assertEquals(JournalEntryStatus::Posted, $entry->status);
        $this->assertNotNull($entry->entry_number);
        $this->assertTrue($entry->isBalanced());
    }

    public function test_unbalanced_entry_throws(): void
    {
        $this->expectException(\App\Exceptions\UnbalancedJournalEntryException::class);
        app(JournalEntryService::class)->createDraft(
            1,
            now(),
            'Bad',
            JournalTemplateKeys::MANUAL,
            [
                new JournalEntryLineDraft('1000', debit: 100),
                new JournalEntryLineDraft('4000', credit: 50),
            ],
        );
    }

    public function test_contra_asset_2001_backfill_sign(): void
    {
        (new FinancialAccountSeeder)->run();
        $account = FinancialAccount::query()->where('code', '2001')->firstOrFail();
        $this->assertTrue($account->is_contra);

        $converter = app(MovementToJournalLineConverter::class);
        $movement = new \App\Models\FinancialAccountMovement([
            'direction' => FinancialAccountMovementDirection::Increase,
            'delta' => 50,
        ]);

        $line = $converter->convert($movement, $account);
        $this->assertEquals(0.0, (float) $line->debit);
        $this->assertEquals(50.0, (float) $line->credit);
    }
}
