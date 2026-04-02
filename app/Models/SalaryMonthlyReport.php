<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $creator_id
 * @property-read Collection|SalaryMonthlyReportLine[] $lines
 * @property-read User|null $creator
 */
class SalaryMonthlyReport extends Model
{
    use BelongsToCompany;

    public const TYPE_ACCRUAL = 'accrual';

    public const TYPE_PAYMENT = 'payment';

    protected $fillable = [
        'company_id',
        'type',
        'date',
        'payment_type',
        'creator_id',
    ];

    protected $casts = [
        'date' => 'date',
        'payment_type' => 'integer',
    ];

    /**
     * @return HasMany<SalaryMonthlyReportLine>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalaryMonthlyReportLine::class, 'salary_monthly_report_id');
    }

    /**
     * @return BelongsTo<User, SalaryMonthlyReport>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
