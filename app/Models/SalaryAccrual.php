<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель массового начисления зарплат
 *
 * @property int $id
 * @property int $company_id ID компании
 * @property int $creator_id ID пользователя, выполнившего начисление
 * @property int $cash_id ID кассы
 * @property string $date Дата начисления
 * @property string|null $note Примечание
 * @property int $total_employees Общее количество сотрудников
 * @property int $success_count Количество успешных начислений
 * @property int $skipped_count Количество пропущенных
 * @property int $errors_count Количество ошибок
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\CashRegister $cashRegister
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\SalaryAccrualItem[] $items
 */
class SalaryAccrual extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'creator_id',
        'cash_id',
        'date',
        'note',
        'total_employees',
        'success_count',
        'skipped_count',
        'errors_count',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Связь с компанией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Связь с кассой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }

    /**
     * Связь с элементами начисления
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items()
    {
        return $this->hasMany(SalaryAccrualItem::class, 'salary_accrual_id');
    }
}

