<?php

namespace App\Models;

use App\Exceptions\InvalidJournalEntryLineException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $journal_entry_id
 * @property int $financial_account_id
 * @property float $debit
 * @property float $credit
 * @property int $line_order
 * @property array<string, mixed>|null $meta
 */
class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'financial_account_id',
        'debit',
        'credit',
        'line_order',
        'meta',
    ];

    protected $casts = [
        'debit' => 'decimal:5',
        'credit' => 'decimal:5',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (JournalEntryLine $line): void {
            $debit = round((float) $line->debit, 5);
            $credit = round((float) $line->credit, 5);

            if ($debit > 0 && $credit > 0) {
                throw new InvalidJournalEntryLineException('Journal line cannot have both debit and credit.');
            }

            if ($debit <= 0 && $credit <= 0) {
                throw new InvalidJournalEntryLineException('Journal line must have either debit or credit.');
            }

            if ($debit < 0 || $credit < 0) {
                throw new InvalidJournalEntryLineException('Journal line amounts cannot be negative.');
            }
        });
    }

    /**
     * @return BelongsTo<JournalEntry, JournalEntryLine>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<FinancialAccount, JournalEntryLine>
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
