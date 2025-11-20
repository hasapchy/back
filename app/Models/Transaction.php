<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\CurrencyConverter;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use App\Services\CacheService;

/**
 * Модель транзакции
 *
 * @property int $id
 * @property float $amount Сумма в валюте кассы
 * @property int $cash_id ID кассы
 * @property int $category_id ID категории транзакции
 * @property int|null $client_id ID клиента
 * @property int $currency_id ID валюты
 * @property \Carbon\Carbon $date Дата транзакции
 * @property string|null $note Примечание
 * @property float $orig_amount Исходная сумма в валюте транзакции
 * @property int|null $project_id ID проекта
 * @property int $type Тип транзакции (0 - расход, 1 - доход)
 * @property int $user_id ID пользователя
 * @property int|null $company_id ID компании
 * @property bool $is_debt Является ли долговой транзакцией
 * @property string|null $source_type Тип источника (morphable)
 * @property int|null $source_id ID источника (morphable)
 * @property bool $is_deleted Флаг удаления
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\CashRegister $cashRegister
 * @property-read \App\Models\TransactionCategory $category
 * @property-read \App\Models\Client|null $client
 * @property-read \App\Models\Currency $currency
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Project|null $project
 * @property-read \App\Models\Company|null $company
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\CashTransfer[] $cashTransfersFrom
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\CashTransfer[] $cashTransfersTo
 * @property-read \Illuminate\Database\Eloquent\Model|null $source
 */
class Transaction extends Model
{
    use HasFactory, LogsActivity {
        LogsActivity::shouldLogEvent as protected traitShouldLogEvent;
    }
    protected $skipClientBalanceUpdate = false;
    protected $skipCashBalanceUpdate = false;
    protected $fillable = [
        'amount',
        'cash_id',
        'category_id',
        'client_id',
        'currency_id',
        'date',
        'note',
        'orig_amount',
        'project_id',
        'type',
        'user_id',
        'company_id',
        'is_debt',
        'source_type',
        'source_id',
        'is_deleted',
    ];

    protected static $logAttributes = [
        'amount',
        'cash_id',
        'category_id',
        'client_id',
        'currency_id',
        'date',
        'note',
        'project_id',
        'type',
        'user_id',
    ];

    protected static $logName = 'transaction';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;

    public function getDescriptionForEvent(string $eventName): string
    {
        switch ($eventName) {
            case 'created':
                return 'Создана транзакция';
            case 'updated':
                return 'Транзакция обновлена';
            case 'deleted':
                return 'Транзакция удалена';
            default:
                return "Транзакция была {$eventName}";
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName('transaction')
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected function shouldLogEvent(string $eventName): bool
    {
        if ($this->is_debt && $this->source_type === Order::class) {
            return false;
        }

        return $this->traitShouldLogEvent($eventName);
    }

    protected $casts = [
        'is_debt' => 'boolean',
        'is_deleted' => 'boolean',
        'amount' => 'decimal:5',
        'orig_amount' => 'decimal:5',
    ];

    protected $hidden = [
        'skipClientBalanceUpdate',
        'skipCashBalanceUpdate',
    ];

    public function setSkipClientBalanceUpdate($value)
    {
        $this->skipClientBalanceUpdate = $value;
    }

    public function getSkipClientBalanceUpdate()
    {
        return $this->skipClientBalanceUpdate;
    }

    public function setSkipCashBalanceUpdate($value)
    {
        $this->skipCashBalanceUpdate = $value;
    }

    public function getSkipCashBalanceUpdate()
    {
        return $this->skipCashBalanceUpdate;
    }

    protected static function booted()
    {
        static::creating(function ($transaction) {
            if (!empty($transaction->no_balance_update)) {
                $transaction->setSkipClientBalanceUpdate(true);
            }
        });

        static::created(function ($transaction) {
            $isOrderWithProject = ($transaction->source_type === 'App\\Models\\Order') && !empty($transaction->project_id);
            if (
                $transaction->client_id
                && !$transaction->getSkipClientBalanceUpdate()
                && !$isOrderWithProject
            ) {
                $client = Client::find($transaction->client_id);
                $defaultCurrency = Currency::where('is_default', true)->first();

                if ($transaction->currency_id != $defaultCurrency->id) {
                    $convertedAmount = CurrencyConverter::convert(
                        $transaction->orig_amount,
                        $transaction->currency,
                        $defaultCurrency
                    );
                } else {
                    $convertedAmount = $transaction->orig_amount;
                }

                if ($client) {
                    if ($transaction->type == 1) {
                        $client->balance = ($client->balance ?? 0) - $convertedAmount;
                    } else {
                        $client->balance = ($client->balance ?? 0) + $convertedAmount;
                    }
                    $client->save();
                }

                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($transaction->client_id);
                CacheService::invalidateProjectsCache();
            }

            $transaction->updateCashBalance();
            CacheService::invalidateTransactionsCache();
        });

        static::updated(function ($transaction) {
            $isOrderWithProject = ($transaction->source_type === 'App\\Models\\Order') && !empty($transaction->project_id);
            if ($transaction->client_id && !$transaction->getSkipClientBalanceUpdate() && !$isOrderWithProject) {
                $client = Client::find($transaction->client_id);
                $defaultCurrency = Currency::where('is_default', true)->first();

                // ВАЖНО: сравнение и откат считаем по исходной сумме/валюте
                $originalAmount = $transaction->getOriginal('orig_amount');
                $originalCurrency = Currency::find($transaction->getOriginal('currency_id'));
                $originalIsDebt = $transaction->getOriginal('is_debt');

                if ($transaction->getOriginal('currency_id') != $defaultCurrency->id) {
                    $originalConverted = CurrencyConverter::convert(
                        $originalAmount,
                        $originalCurrency,
                        $defaultCurrency
                    );
                } else {
                    $originalConverted = $originalAmount;
                }

                if ($transaction->currency_id != $defaultCurrency->id) {
                    $currentConverted = CurrencyConverter::convert(
                        $transaction->orig_amount,
                        $transaction->currency,
                        $defaultCurrency
                    );
                } else {
                    $currentConverted = $transaction->orig_amount;
                }

                if ($client) {
                    $oldClientId = $transaction->getOriginal('client_id');
                    $originalType = $transaction->getOriginal('type');

                    if ($oldClientId && $oldClientId != $transaction->client_id) {
                        // Клиент изменился: откатить на старом клиенте, применить на новом
                        $oldClient = Client::find($oldClientId);
                        if ($oldClient) {
                            if ($originalType == 1) {
                                $oldClient->balance = ($oldClient->balance ?? 0) + $originalConverted;
                            } else {
                                $oldClient->balance = ($oldClient->balance ?? 0) - $originalConverted;
                            }
                            $oldClient->save();
                        }

                        if ($transaction->type == 1) {
                            $client->balance = ($client->balance ?? 0) - $currentConverted;
                        } else {
                            $client->balance = ($client->balance ?? 0) + $currentConverted;
                        }
                        $client->save();
                    } elseif (!$oldClientId) {
                        if ($transaction->type == 1) {
                            $client->balance = ($client->balance ?? 0) - $currentConverted;
                        } else {
                            $client->balance = ($client->balance ?? 0) + $currentConverted;
                        }
                        $client->save();
                    } else {
                        if ($originalType == 1) {
                            $client->balance = ($client->balance ?? 0) + $originalConverted;
                        } else {
                            $client->balance = ($client->balance ?? 0) - $originalConverted;
                        }
                        if ($transaction->type == 1) {
                            $client->balance = ($client->balance ?? 0) - $currentConverted;
                        } else {
                            $client->balance = ($client->balance ?? 0) + $currentConverted;
                        }
                        $client->save();
                    }
                }

                // Инвалидируем кэш клиента и проектов после обновления баланса
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($transaction->client_id);
                CacheService::invalidateProjectsCache();
            }

            CacheService::invalidateTransactionsCache();
        });

        static::deleted(function ($transaction) {
            if ($transaction->client_id && !$transaction->getSkipClientBalanceUpdate()) {
                $client = Client::find($transaction->client_id);
                $defaultCurrency = Currency::where('is_default', true)->first();

                if ($transaction->currency_id != $defaultCurrency->id) {
                    $convertedAmount = CurrencyConverter::convert(
                        $transaction->orig_amount,
                        $transaction->currency,
                        $defaultCurrency
                    );
                } else {
                    $convertedAmount = $transaction->orig_amount;
                }

                if ($client) {
                    if ($transaction->is_debt) {
                        if ($transaction->type == 1) {
                            $client->balance = ($client->balance ?? 0) - $convertedAmount;
                        } else {
                            $client->balance = ($client->balance ?? 0) + $convertedAmount;
                        }
                    } else {
                        if ($transaction->type == 1) {
                            $client->balance = ($client->balance ?? 0) + $convertedAmount;
                        } else {
                            $client->balance = ($client->balance ?? 0) - $convertedAmount;
                        }
                    }
                    $client->save();
                }

                // Инвалидируем кэш клиента и проектов после обновления баланса
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($transaction->client_id);
                CacheService::invalidateProjectsCache();
            }

            if ($transaction->cash_id && !$transaction->is_debt && !$transaction->getSkipCashBalanceUpdate()) {
                $cash = CashRegister::find($transaction->cash_id);
                if ($cash) {
                    if ($transaction->type == 1) {
                        $cash->balance -= $transaction->amount;
                    } else {
                        $cash->balance += $transaction->amount;
                    }
                    $cash->save();
                }
            }

            CacheService::invalidateTransactionsCache();
        });
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
     * Связь с категорией транзакции
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    /**
     * Связь с клиентом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Связь с поставщиком
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function supplier()
    {
        return $this->belongsTo(Client::class, 'supplier_id')->where('is_supplier', true);
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

    /**
     * Связь с пользователем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Morphable связь с источником транзакции
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function source()
    {
        return $this->morphTo();
    }

    /**
     * Связь с переводами из этой транзакции
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function cashTransfersFrom()
    {
        return $this->hasMany(CashTransfer::class, 'tr_id_from');
    }

    /**
     * Связь с переводами в эту транзакцию
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function cashTransfersTo()
    {
        return $this->hasMany(CashTransfer::class, 'tr_id_to');
    }

    /**
     * Связь с проектом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Получить курс обмена валюты для компании
     *
     * @return float|null
     */
    public function getExchangeRateAttribute()
    {
        $currency = $this->currency;
        if (!$currency) {
            return null;
        }

        $companyId = $this->company_id;
        $rate = $currency->getExchangeRateForCompany($companyId, $this->date ? $this->date->toDateString() : null);

        return $rate;
    }

    /**
     * Связь с активностями (activity log)
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function activities()
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    /**
     * Связь с комментариями
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function comments()
    {
        return $this->morphMany(\App\Models\Comment::class, 'commentable');
    }

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
     * Обновить баланс кассы
     *
     * ВНИМАНИЕ: Операция не атомарна и может привести к race conditions при параллельных запросах.
     * Рекомендуется обернуть вызов этого метода в DB::transaction() с блокировкой строки.
     *
     * @return void
     */
    public function updateCashBalance()
    {
        if ($this->getSkipCashBalanceUpdate() || $this->is_debt || !$this->cash_id) {
            return;
        }

        $cash = CashRegister::find($this->cash_id);
        if ($cash) {
            if ($this->type == 1) {
                $cash->balance += $this->amount;
            } else {
                $cash->balance -= $this->amount;
            }
            $cash->save();
        }
    }
}
