<?php

namespace App\Models;

use App\Enums\JournalEntryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $company_id
 * @property string|null $entry_number
 * @property \Carbon\Carbon $entry_date
 * @property string|null $description
 * @property JournalEntryStatus $status
 * @property string|null $template_key
 * @property string|null $source_type
 * @property int|null $source_id
 * @property array<string, mixed>|null $meta
 * @property int|null $created_by
 * @property int|null $posted_by
 * @property \Carbon\Carbon|null $posted_at
 * @property int|null $reverses_entry_id
 * @property int|null $reversed_by_entry_id
 */
class JournalEntry extends Model
{
    protected $fillable = [
        'company_id',
        'entry_number',
        'entry_date',
        'description',
        'status',
        'template_key',
        'source_type',
        'source_id',
        'meta',
        'created_by',
        'posted_by',
        'posted_at',
        'reverses_entry_id',
        'reversed_by_entry_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'status' => JournalEntryStatus::class,
        'meta' => 'array',
        'posted_at' => 'datetime',
    ];

    /**
     * @return HasMany<JournalEntryLine>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_order');
    }

    /**
     * @return MorphTo<Model, JournalEntry>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<JournalEntry, JournalEntry>
     */
    public function reversesEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, JournalEntry>
     */
    public function reversedByEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    /**
     * @return bool
     */
    public function isBalanced(): bool
    {
        $this->loadMissing('lines');

        $debit = round((float) $this->lines->sum('debit'), 5);
        $credit = round((float) $this->lines->sum('credit'), 5);

        return abs($debit - $credit) < 0.00001 && $debit > 0;
    }

    /**
     * @return bool
     */
    public function canPost(): bool
    {
        return $this->status === JournalEntryStatus::Draft
            && $this->lines()->count() >= 2
            && $this->isBalanced();
    }

    /**
     * @return bool
     */
    public function canReverse(): bool
    {
        return $this->status === JournalEntryStatus::Posted
            && $this->reversed_by_entry_id === null;
    }
}
