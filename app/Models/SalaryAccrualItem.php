<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель элемента массового начисления зарплаты
 *
 * @property int $id
 * @property int $salary_accrual_id ID массового начисления
 * @property int $employee_id ID сотрудника
 * @property int|null $transaction_id ID транзакции
 * @property int|null $employee_salary_id ID зарплаты сотрудника
 * @property float $amount Сумма
 * @property int $currency_id ID валюты
 * @property string $status Статус (success, skipped, error)
 * @property string|null $error_message Сообщение об ошибке
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\SalaryAccrual $salaryAccrual
 * @property-read \App\Models\User $employee
 * @property-read \App\Models\Transaction|null $transaction
 * @property-read \App\Models\EmployeeSalary|null $employeeSalary
 * @property-read \App\Models\Currency $currency
 */
class SalaryAccrualItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'salary_accrual_id',
        'employee_id',
        'transaction_id',
        'employee_salary_id',
        'amount',
        'currency_id',
        'status',
        'error_message',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Связь с массовым начислением
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function salaryAccrual()
    {
        return $this->belongsTo(SalaryAccrual::class, 'salary_accrual_id');
    }

    /**
     * Связь с сотрудником
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * Связь с транзакцией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /**
     * Связь с зарплатой сотрудника
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function employeeSalary()
    {
        return $this->belongsTo(EmployeeSalary::class, 'employee_salary_id');
    }

    /**
     * Связь с валютой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}

