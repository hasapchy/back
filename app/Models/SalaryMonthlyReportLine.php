<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryMonthlyReportLine extends Model
{
    protected $fillable = [
        'salary_monthly_report_id',
        'employee_id',
        'currency_id',
        'employee_name',
        'amount',
        'transaction_id',
        'official_working_days_norm',
        'official_working_days_worked',
        'monthly_salary_base',
        'prorated_salary_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'monthly_salary_base' => 'decimal:2',
        'prorated_salary_amount' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<SalaryMonthlyReport, SalaryMonthlyReportLine>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(SalaryMonthlyReport::class, 'salary_monthly_report_id');
    }

    /**
     * @return BelongsTo<User, SalaryMonthlyReportLine>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * @return BelongsTo<Currency, SalaryMonthlyReportLine>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
