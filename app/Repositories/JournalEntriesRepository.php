<?php

namespace App\Repositories;

use App\DTO\JournalEntryLineDraft;
use App\Enums\JournalEntryStatus;
use App\Exceptions\FinancialAccountNotFoundException;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\JournalEntryNumberGenerator;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class JournalEntriesRepository extends BaseRepository
{
    public function __construct(
        private readonly JournalEntryNumberGenerator $numberGenerator,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, JournalEntry>
     */
    public function paginate(array $filters = [], int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $companyId = $this->getCurrentCompanyId();
        $query = JournalEntry::query()
            ->with(['lines.financialAccount'])
            ->where('company_id', $companyId)
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  int  $id
     * @return JournalEntry|null
     */
    public function findForCompany(int $id): ?JournalEntry
    {
        return JournalEntry::query()
            ->with(['lines.financialAccount'])
            ->where('company_id', $this->getCurrentCompanyId())
            ->find($id);
    }

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
        return JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('template_key', $templateKey)
            ->whereIn('status', [JournalEntryStatus::Posted, JournalEntryStatus::Draft])
            ->first();
    }

    /**
     * @param  int  $companyId
     * @param  string  $sourceType
     * @param  int  $sourceId
     * @param  string  $templateKey
     * @return JournalEntry|null
     */
    public function findBySourceForUpdate(
        int $companyId,
        string $sourceType,
        int $sourceId,
        string $templateKey,
    ): ?JournalEntry {
        return JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('template_key', $templateKey)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  int  $companyId
     * @param  Carbon  $entryDate
     * @param  string|null  $description
     * @param  string  $templateKey
     * @param  string|null  $sourceType
     * @param  int|null  $sourceId
     * @param  array<string, mixed>  $meta
     * @return JournalEntry
     */
    public function createDraftRecord(
        int $companyId,
        Carbon $entryDate,
        ?string $description,
        string $templateKey,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $meta = [],
    ): JournalEntry {
        return JournalEntry::query()->create([
            'company_id' => $companyId,
            'entry_number' => null,
            'entry_date' => $entryDate->toDateString(),
            'description' => $description,
            'status' => JournalEntryStatus::Draft,
            'template_key' => $templateKey,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'meta' => $meta !== [] ? $meta : null,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * @param  JournalEntry  $entry
     * @param  list<JournalEntryLineDraft>  $lines
     * @return void
     */
    public function replaceLines(JournalEntry $entry, array $lines): void
    {
        JournalEntryLine::query()->where('journal_entry_id', $entry->id)->delete();
        $this->persistLines($entry, $lines);
    }

    /**
     * @param  JournalEntry  $entry
     * @param  list<JournalEntryLineDraft>  $lines
     * @return void
     */
    public function persistLines(JournalEntry $entry, array $lines): void
    {
        $accounts = $this->resolveAccountsByCode($lines);

        foreach ($lines as $index => $draft) {
            $account = $accounts->get($draft->accountCode);
            if ($account === null) {
                throw new FinancialAccountNotFoundException("Financial account not found: {$draft->accountCode}");
            }

            JournalEntryLine::query()->create([
                'journal_entry_id' => $entry->id,
                'financial_account_id' => $account->id,
                'debit' => round($draft->debit, 5),
                'credit' => round($draft->credit, 5),
                'line_order' => $index,
                'meta' => $draft->meta !== [] ? $draft->meta : null,
            ]);
        }
    }

    /**
     * @param  JournalEntry  $entry
     * @return JournalEntry
     */
    public function markPosted(JournalEntry $entry): JournalEntry
    {
        $entryNumber = $this->numberGenerator->next(
            (int) $entry->company_id,
            Carbon::parse($entry->entry_date),
        );

        $entry->entry_number = $entryNumber;
        $entry->status = JournalEntryStatus::Posted;
        $entry->posted_at = now();
        $entry->posted_by = Auth::id();
        $entry->save();

        return $entry->fresh(['lines.financialAccount']);
    }

    /**
     * @param  JournalEntry  $entry
     * @param  int  $reversalEntryId
     * @return void
     */
    public function markReversed(JournalEntry $entry, int $reversalEntryId): void
    {
        $entry->status = JournalEntryStatus::Reversed;
        $entry->reversed_by_entry_id = $reversalEntryId;
        $entry->save();
    }

    /**
     * @param  JournalEntry  $reversal
     * @param  int  $originalEntryId
     * @return void
     */
    public function linkReversal(JournalEntry $reversal, int $originalEntryId): void
    {
        $reversal->reverses_entry_id = $originalEntryId;
        $reversal->save();
    }

    /**
     * @param  Builder<JournalEntry>  $query
     * @param  array<string, mixed>  $filters
     * @return void
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['template_key'])) {
            $query->where('template_key', $filters['template_key']);
        }
        if (! empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }
        if (! empty($filters['date_from'])) {
            $query->where('entry_date', '>=', Carbon::parse((string) $filters['date_from'])->toDateString());
        }
        if (! empty($filters['date_to'])) {
            $query->where('entry_date', '<=', Carbon::parse((string) $filters['date_to'])->toDateString());
        }
        if (! empty($filters['account_id'])) {
            $accountId = (int) $filters['account_id'];
            $query->whereHas('lines', fn ($q) => $q->where('financial_account_id', $accountId));
        }
        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('entry_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
    }

    /**
     * @param  list<JournalEntryLineDraft>  $lines
     * @return Collection<string, FinancialAccount>
     */
    private function resolveAccountsByCode(array $lines): Collection
    {
        $codes = collect($lines)->pluck('accountCode')->unique()->values()->all();

        return FinancialAccount::query()
            ->whereIn('code', $codes)
            ->where('is_active', true)
            ->get()
            ->keyBy('code');
    }
}
