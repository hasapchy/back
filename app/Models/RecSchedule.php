<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Расписание повторяющейся транзакции (по шаблону)
 *
 * @property int $id
 * @property int $creator_id
 * @property int|null $company_id
 * @property int $template_id
 * @property \Carbon\Carbon $start_date
 * @property array $recurrence_rule
 * @property \Carbon\Carbon|null $end_date
 * @property int|null $end_count
 * @property int $occurrence_count
 * @property \Carbon\Carbon $next_run_at
 * @property bool $is_active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\Template $template
 */
class RecSchedule extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'rec_schedules';

    protected $fillable = [
        'creator_id',
        'company_id',
        'template_id',
        'start_date',
        'recurrence_rule',
        'end_date',
        'end_count',
        'occurrence_count',
        'next_run_at',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'recurrence_rule' => 'array',
        'end_date' => 'date',
        'next_run_at' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }
}
