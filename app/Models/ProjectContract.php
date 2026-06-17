<?php

namespace App\Models;

use App\Enums\ProjectContractStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\User;
use App\Models\Comment;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use App\Contracts\SupportsTimeline;

/**
 * Модель контракта проекта
 *
 * @property int $id
 * @property int $project_id ID проекта
 * @property ProjectContractStatus $status Статус контракта (draft|active)
 * @property int|null $client_id ID клиента (как у проекта на момент сохранения)
 * @property int|null $creator_id ID пользователя, создавшего контракт
 * @property string $number Номер контракта
 * @property int $type Тип контракта (0 - безналичный, 1 - наличный)
 * @property float $amount Сумма контракта
 * @property int $currency_id ID валюты
 * @property int|null $cash_id ID кассы
 * @property int|null $client_balance_id ID баланса клиента
 * @property \Carbon\Carbon $date Дата контракта
 * @property bool $returned Подписан ли контракт
 * @property float $paid_amount Оплаченная сумма
 * @property array|null $files Массив файлов
 * @property string|null $note Примечание
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Project $project
 * @property-read \App\Models\Client|null $client
 * @property-read \App\Models\User|null $creator
 * @property-read \App\Models\Currency $currency
 * @property-read \App\Models\CashRegister|null $cashRegister
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read string $formatted_amount Отформатированная сумма с валютой
 * @property-read string $returned_status Статус подписания контракта
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Comment[] $comments
 */
class ProjectContract extends Model implements SupportsTimeline
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'project_id',
        'status',
        'client_id',
        'creator_id',
        'number',
        'type',
        'amount',
        'currency_id',
        'cash_id',
        'client_balance_id',
        'date',
        'returned',
        'paid_amount',
        'files',
        'note'
    ];

    protected $casts = [
        'status' => ProjectContractStatus::class,
        'date' => 'datetime',
        'type' => 'integer',
        'returned' => 'boolean',
        'files' => 'array',
        'amount' => 'decimal:5',
        'paid_amount' => 'decimal:5'
    ];

    /**
     * @return string
     */
    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created', 'updated', 'deleted' => "activity_log.project_contract.{$eventName}",
            default => 'activity_log.project_contract.default',
        };
    }

    /**
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'project_id',
                'status',
                'client_id',
                'creator_id',
                'number',
                'type',
                'amount',
                'currency_id',
                'cash_id',
                'date',
                'returned',
                'paid_amount',
                'note',
                'files',
            ])
            ->useLogName('project_contract')
            ->dontSubmitEmptyLogs()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    /**
     * @param Activity $activity
     * @param string $eventName
     * @return void
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        if ($eventName !== 'updated' || ! $this->wasChanged('returned')) {
            return;
        }

        $activity->description = $this->returned
            ? 'activity_log.project_contract.returned_signed'
            : 'activity_log.project_contract.returned_unsigned';
    }

    /**
     * Связь с проектом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Связь с создателем контракта
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Связь с валютой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class);
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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function clientBalance()
    {
        return $this->belongsTo(ClientBalance::class, 'client_balance_id');
    }

    /**
     * Morphable связь с транзакциями
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    /**
     * @return ProjectContractStatus
     */
    public function contractStatus(): ProjectContractStatus
    {
        $status = $this->status;

        return $status instanceof ProjectContractStatus
            ? $status
            : ProjectContractStatus::tryFrom((string) ($status ?? '')) ?? ProjectContractStatus::Draft;
    }

    /**
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->contractStatus() === ProjectContractStatus::Draft;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->contractStatus() === ProjectContractStatus::Active;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    /**
     * Accessor для получения отформатированной суммы с валютой
     *
     * @return string
     */
    public function getFormattedAmountAttribute()
    {
        $code = $this->currency ? $this->currency->code : '';
        return number_format($this->amount, 2) . ' ' . $code;
    }

    /**
     * Accessor для получения статуса возврата контракта
     *
     * @return string
     */
    public function getReturnedStatusAttribute()
    {
        return $this->returned
            ? 'activity_log.project_contract.returned_signed'
            : 'activity_log.project_contract.returned_unsigned';
    }
}
