<?php

namespace App\Services;

use App\DTO\JournalEntryLineDraft;
use App\Enums\JournalEntryStatus;
use App\Exceptions\JournalEntryAlreadyPostedException;
use App\Exceptions\JournalEntryNotReversibleException;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Models\JournalEntry;
use App\Repositories\JournalEntriesRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class JournalEntryService
{
    public function __construct(
        private readonly JournalEntriesRepository $repository,
    ) {}

    /**
     * @param  int  $companyId
     * @param  string  $sourceType
     * @param  int  $sourceId
     * @param  string  $templateKey
     * @return JournalEntry|null
     */
    public function findBySource(
        int $companyId,
        string $sourceType,
        int $sourceId,
        string $templateKey,
    ): ?JournalEntry {
        return $this->repository->findBySource($companyId, $sourceType, $sourceId, $templateKey);
    }

    /**
     * @param  int  $companyId
     * @param  Carbon  $entryDate
     * @param  string|null  $description
     * @param  string  $templateKey
     * @param  list<JournalEntryLineDraft>  $lines
     * @param  string|null  $sourceType
     * @param  int|null  $sourceId
     * @param  array<string, mixed>  $meta
     * @return JournalEntry|null
     */
    public function createDraft(
        int $companyId,
        Carbon $entryDate,
        ?string $description,
        string $templateKey,
        array $lines,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $meta = [],
    ): ?JournalEntry {
        if (! $this->repository->lineAccountsExist($lines)) {
            return null;
        }

        $this->assertBalanced($lines);

        $entry = $this->repository->createDraftRecord(
            $companyId,
            $entryDate,
            $description,
            $templateKey,
            $sourceType,
            $sourceId,
            $meta,
        );
        $this->repository->persistLines($entry, $lines);

        return $entry->fresh(['lines.financialAccount']);
    }

    /**
     * @param  JournalEntry  $entry
     * @return JournalEntry
     */
    public function post(JournalEntry $entry): JournalEntry
    {
        if ($entry->status !== JournalEntryStatus::Draft) {
            throw new JournalEntryAlreadyPostedException('Only draft entries can be posted.');
        }

        $entry->loadMissing('lines');
        if (! $entry->canPost()) {
            throw new UnbalancedJournalEntryException('Journal entry cannot be posted: unbalanced or insufficient lines.');
        }

        return DB::transaction(function () use ($entry): JournalEntry {
            $locked = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== JournalEntryStatus::Draft) {
                throw new JournalEntryAlreadyPostedException('Journal entry already posted.');
            }

            return $this->repository->markPosted($locked);
        });
    }

    /**
     * @param  int  $companyId
     * @param  Carbon  $entryDate
     * @param  string|null  $description
     * @param  string  $templateKey
     * @param  list<JournalEntryLineDraft>  $lines
     * @param  string|null  $sourceType
     * @param  int|null  $sourceId
     * @param  array<string, mixed>  $meta
     * @return JournalEntry|null
     */
    public function createAndPost(
        int $companyId,
        Carbon $entryDate,
        ?string $description,
        string $templateKey,
        array $lines,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $meta = [],
    ): ?JournalEntry {
        if (! $this->repository->lineAccountsExist($lines)) {
            return null;
        }

        $this->assertBalanced($lines);

        if ($sourceType !== null && $sourceId !== null) {
            $existing = $this->repository->findBySource($companyId, $sourceType, $sourceId, $templateKey);
            if ($existing !== null && $existing->status === JournalEntryStatus::Posted) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($companyId, $entryDate, $description, $templateKey, $lines, $sourceType, $sourceId, $meta): ?JournalEntry {
            if ($sourceType !== null && $sourceId !== null) {
                $existing = $this->repository->findBySourceForUpdate($companyId, $sourceType, $sourceId, $templateKey);

                if ($existing !== null && $existing->status === JournalEntryStatus::Posted) {
                    return $existing;
                }

                if ($existing !== null && $existing->status === JournalEntryStatus::Draft) {
                    $this->repository->replaceLines($existing, $lines);

                    return $this->post($existing->fresh(['lines.financialAccount']));
                }
            }

            $draft = $this->createDraft(
                $companyId,
                $entryDate,
                $description,
                $templateKey,
                $lines,
                $sourceType,
                $sourceId,
                $meta,
            );

            if ($draft === null) {
                return null;
            }

            return $this->post($draft);
        });
    }

    /**
     * @param  JournalEntry  $entry
     * @param  string|null  $reason
     * @return JournalEntry|null
     */
    public function reverse(JournalEntry $entry, ?string $reason = null): ?JournalEntry
    {
        if (! $entry->canReverse()) {
            throw new JournalEntryNotReversibleException('Journal entry cannot be reversed.');
        }

        return DB::transaction(function () use ($entry, $reason): ?JournalEntry {
            $entry->loadMissing('lines.financialAccount');

            $mirrorLines = [];
            foreach ($entry->lines as $line) {
                $mirrorLines[] = new JournalEntryLineDraft(
                    accountCode: $line->financialAccount->code,
                    debit: (float) $line->credit,
                    credit: (float) $line->debit,
                    meta: $line->meta ?? [],
                );
            }

            $reversal = $this->createAndPost(
                (int) $entry->company_id,
                Carbon::parse($entry->entry_date),
                $reason ?? ('Reversal of '.$entry->entry_number),
                $entry->template_key.'_reversal',
                $mirrorLines,
                $entry->source_type,
                $entry->source_id !== null ? (int) $entry->source_id : null,
                array_merge($entry->meta ?? [], ['reverses_entry_id' => $entry->id]),
            );

            if ($reversal === null) {
                return null;
            }

            $this->repository->markReversed($entry, (int) $reversal->id);
            $this->repository->linkReversal($reversal, (int) $entry->id);

            return $reversal;
        });
    }

    /**
     * @param  list<JournalEntryLineDraft>  $lines
     * @return void
     */
    private function assertBalanced(array $lines): void
    {
        $debit = round(collect($lines)->sum(fn (JournalEntryLineDraft $l) => $l->debit), 5);
        $credit = round(collect($lines)->sum(fn (JournalEntryLineDraft $l) => $l->credit), 5);

        if (abs($debit - $credit) > 0.00001 || $debit <= 0) {
            throw new UnbalancedJournalEntryException("Unbalanced journal entry: debit={$debit}, credit={$credit}");
        }
    }
}
