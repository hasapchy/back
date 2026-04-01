<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read \Illuminate\Database\Eloquent\Collection|SalaryMonthlyReportLine[] $lines
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
}
