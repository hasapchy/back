<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\CurrencyConverter;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use App\Services\CacheService;

class Transaction extends Model
{
    use HasFactory, LogsActivity;
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

    protected $casts = [
        'is_debt' => 'boolean',
        'amount' => 'decimal:2',
        'orig_amount' => 'decimal:2',
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
            // Если передали кастомный флаг no_balance_update = true
            if (!empty($transaction->no_balance_update)) {
                $transaction->setSkipClientBalanceUpdate(true);
            }
            \Illuminate\Support\Facades\Log::info('Transaction::creating', [
                'client_id' => $transaction->client_id,
                'is_debt' => $transaction->is_debt,
                'type' => $transaction->type,
                'skipClientBalanceUpdate' => $transaction->getSkipClientBalanceUpdate()
            ]);
        });

        static::created(function ($transaction) {
            \Illuminate\Support\Facades\Log::info('Transaction::created - START', [
                'transaction_id' => $transaction->id,
                'client_id' => $transaction->client_id,
                'is_debt' => $transaction->is_debt,
                'type' => $transaction->type,
                'skipClientBalanceUpdate' => $transaction->getSkipClientBalanceUpdate(),
                'amount' => $transaction->amount,
                'orig_amount' => $transaction->orig_amount
            ]);

            // Обновляем баланс клиента только если это НЕ долговая операция (is_debt=false)
            if (
                $transaction->client_id
                && !$transaction->getSkipClientBalanceUpdate()
            ) {
                \Illuminate\Support\Facades\Log::info('Transaction::created - UPDATING CLIENT BALANCE', [
                    'transaction_id' => $transaction->id,
                    'client_id' => $transaction->client_id,
                    'reason' => 'client_id exists and skipClientBalanceUpdate is false'
                ]);

                $client = Client::find($transaction->client_id);
                $defaultCurrency = Currency::where('is_default', true)->first();

                if ($transaction->currency_id != $defaultCurrency->id) {
                    $convertedAmount = CurrencyConverter::convert(
                        $transaction->amount,
                        $transaction->currency,
                        $defaultCurrency
                    );
                } else {
                    $convertedAmount = $transaction->amount;
                }

                if ($client) {
                    $oldBalance = $client->balance;
                    // ЛОГИКА: Положительный баланс = "клиент должен НАМ"
                    // type=1 (доход): клиент ПЛАТИТ → долг УМЕНЬШАЕТСЯ
                    // type=0 (расход): мы ПЛАТИМ → долг УВЕЛИЧИВАЕТСЯ
                    if ($transaction->type == 1) {
                        $client->balance = ($client->balance ?? 0) - $convertedAmount;
                    } else {
                        $client->balance = ($client->balance ?? 0) + $convertedAmount;
                    }
                    $client->save();

                    \Illuminate\Support\Facades\Log::info('Transaction::created - CLIENT BALANCE CHANGED', [
                        'transaction_id' => $transaction->id,
                        'client_id' => $transaction->client_id,
                        'old_balance' => $oldBalance,
                        'new_balance' => $client->balance,
                        'operation' => $transaction->type == 1 ? 'SUBTRACT (Income - client pays)' : 'ADD (Expense - we pay)',
                        'amount' => $convertedAmount
                    ]);
                } else {
                    \Illuminate\Support\Facades\Log::warning('Transaction::created - CLIENT NOT FOUND', [
                        'transaction_id' => $transaction->id,
                        'client_id' => $transaction->client_id
                    ]);
                }

                // Инвалидируем кэш клиента и проектов после обновления баланса
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($transaction->client_id);
                CacheService::invalidateProjectsCache();
            } else {
                \Illuminate\Support\Facades\Log::info('Transaction::created - CLIENT BALANCE UPDATE SKIPPED', [
                    'transaction_id' => $transaction->id,
                    'client_id' => $transaction->client_id,
                    'reason_client_id' => empty($transaction->client_id) ? 'no_client_id' : 'has_client_id',
                    'reason_skip' => $transaction->getSkipClientBalanceUpdate() ? 'skip_flag_set' : 'skip_flag_not_set'
                ]);
            }

            // Обновляем баланс кассы (если не долг)
            $transaction->updateCashBalance();

            // Инвалидируем кэш списков транзакций
            \App\Services\CacheService::invalidateTransactionsCache();

            \Illuminate\Support\Facades\Log::info('Transaction::created - COMPLETED', [
                'transaction_id' => $transaction->id,
                'client_id' => $transaction->client_id
            ]);
        });

        static::updated(function ($transaction) {
            \Illuminate\Support\Facades\Log::info('Transaction::updated - START', [
                'transaction_id' => $transaction->id,
                'client_id' => $transaction->client_id,
                'is_debt' => $transaction->is_debt,
                'type' => $transaction->type,
                'skipClientBalanceUpdate' => $transaction->getSkipClientBalanceUpdate()
            ]);

            // Обновляем баланс клиента только если это НЕ долговая операция (is_debt=false)
            if ($transaction->client_id && !$transaction->getSkipClientBalanceUpdate()) {
                $client = Client::find($transaction->client_id);
                $defaultCurrency = Currency::where('is_default', true)->first();

                $originalAmount = $transaction->getOriginal('amount');
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
                        $transaction->amount,
                        $transaction->currency,
                        $defaultCurrency
                    );
                } else {
                    $currentConverted = $transaction->amount;
                }

                if ($client) {
                    $oldBalance = $client->balance;
                    $originalType = $transaction->getOriginal('type');

                    // Откатываем старое значение (противоположная операция)
                    // Было type=1 (доход): был -=, откатываем +=
                    // Было type=0 (расход): был +=, откатываем -=
                    if ($originalType == 1) {
                        $client->balance = ($client->balance ?? 0) + $originalConverted;
                    } else {
                        $client->balance = ($client->balance ?? 0) - $originalConverted;
                    }

                    // Применяем новое значение (прямая операция)
                    // ЛОГИКА: Положительный баланс = "клиент должен НАМ"
                    // type=1 (доход): клиент ПЛАТИТ → долг УМЕНЬШАЕТСЯ
                    // type=0 (расход): мы ПЛАТИМ → долг УВЕЛИЧИВАЕТСЯ
                    if ($transaction->type == 1) {
                        $client->balance = ($client->balance ?? 0) - $currentConverted;
                    } else {
                        $client->balance = ($client->balance ?? 0) + $currentConverted;
                    }

                    $client->save();

                    \Illuminate\Support\Facades\Log::info('Transaction::updated - CLIENT BALANCE CHANGED', [
                        'transaction_id' => $transaction->id,
                        'client_id' => $transaction->client_id,
                        'old_balance' => $oldBalance,
                        'new_balance' => $client->balance,
                        'old_amount' => $originalAmount,
                        'new_amount' => $transaction->amount
                    ]);
                }

                // Инвалидируем кэш клиента и проектов после обновления баланса
                \App\Services\CacheService::invalidateClientsCache();
                \App\Services\CacheService::invalidateClientBalanceCache($transaction->client_id);
                \App\Services\CacheService::invalidateProjectsCache();
            }

            // Инвалидируем кэш списков транзакций
            \App\Services\CacheService::invalidateTransactionsCache();

            \Illuminate\Support\Facades\Log::info('Transaction::updated - COMPLETED', [
                'transaction_id' => $transaction->id,
                'client_id' => $transaction->client_id
            ]);
        });

        static::deleted(function ($transaction) {
            \Illuminate\Support\Facades\Log::info('Transaction::deleted - START', [
                'transaction_id' => $transaction->id,
                'client_id' => $transaction->client_id,
                'is_debt' => $transaction->is_debt,
                'type' => $transaction->type,
                'skipClientBalanceUpdate' => $transaction->getSkipClientBalanceUpdate()
            ]);

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
                    $oldBalance = $client->balance;
                    // Откатываем операцию при удалении транзакции
                    if ($transaction->is_debt) {
                        // Долговые транзакции:
                        // type=1 (доход): при создании было balance +=, откатываем balance -=
                        // type=0 (расход): при создании было balance -=, откатываем balance +=
                        if ($transaction->type == 1) {
                            $client->balance = ($client->balance ?? 0) - $convertedAmount;
                        } else {
                            $client->balance = ($client->balance ?? 0) + $convertedAmount;
                        }
                    } else {
                        // Обычные транзакции:
                        // type=1 (доход): при создании было balance -=, откатываем balance +=
                        // type=0 (расход): при создании было balance +=, откатываем balance -=
                        if ($transaction->type == 1) {
                            $client->balance = ($client->balance ?? 0) + $convertedAmount;
                        } else {
                            $client->balance = ($client->balance ?? 0) - $convertedAmount;
                        }
                    }
                    $client->save();

                    \Illuminate\Support\Facades\Log::info('Transaction::deleted - CLIENT BALANCE CHANGED', [
                        'transaction_id' => $transaction->id,
                        'client_id' => $transaction->client_id,
                        'old_balance' => $oldBalance,
                        'new_balance' => $client->balance,
                        'amount' => $convertedAmount
                    ]);
                }

                // Инвалидируем кэш клиента и проектов после обновления баланса
                \App\Services\CacheService::invalidateClientsCache();
                \App\Services\CacheService::invalidateClientBalanceCache($transaction->client_id);
                \App\Services\CacheService::invalidateProjectsCache();
            }

            // Откатываем баланс кассы (если не установлен флаг пропуска)
            if ($transaction->cash_id && !$transaction->is_debt && !$transaction->getSkipCashBalanceUpdate()) {
                $cash = CashRegister::find($transaction->cash_id);
                if ($cash) {
                    if ($transaction->type == 1) {
                        $cash->balance -= $transaction->amount; // доход → откатываем
                    } else {
                        $cash->balance += $transaction->amount; // расход → откатываем
                    }
                    $cash->save();

                    \Illuminate\Support\Facades\Log::info('Transaction::deleted - CASH BALANCE CHANGED', [
                        'transaction_id' => $transaction->id,
                        'cash_id' => $transaction->cash_id,
                        'new_balance' => $cash->balance,
                        'amount' => $transaction->amount
                    ]);
                }
            }

            // Инвалидируем кэш списков транзакций
            \App\Services\CacheService::invalidateTransactionsCache();

            \Illuminate\Support\Facades\Log::info('Transaction::deleted - COMPLETED', [
                'transaction_id' => $transaction->id,
                'client_id' => $transaction->client_id
            ]);
        });
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }

    public function category()
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Client::class, 'supplier_id')->where('is_supplier', true);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }


    public function source()
    {
        return $this->morphTo();
    }

    public function cashTransfersFrom()
    {
        return $this->hasMany(CashTransfer::class, 'tr_id_from');
    }

    public function cashTransfersTo()
    {
        return $this->hasMany(CashTransfer::class, 'tr_id_to');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function getExchangeRateAttribute()
    {
        $currency = $this->currency;
        if (!$currency) {
            return null;
        }

        $rateHistory = $currency->exchangeRateHistories()
            ->where('start_date', '<=', $this->date)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $this->date);
            })
            ->orderBy('start_date', 'desc')
            ->first();

        return $rateHistory ? $rateHistory->exchange_rate : null;
    }

    public function activities()
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    public function comments()
    {
        return $this->morphMany(\App\Models\Comment::class, 'commentable');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }


    public function updateCashBalance()
    {
        if ($this->getSkipCashBalanceUpdate() || $this->is_debt || !$this->cash_id) {
            // Пропускаем обновление кассы если:
            // 1. Установлен флаг skipCashBalanceUpdate (касса уже обновлена в репозитории)
            // 2. Долг - касса НЕ меняется
            // 3. Нет кассы
            return;
        }

        // Обычная транзакция - касса меняется
        $cash = CashRegister::find($this->cash_id);
        if ($cash) {
            if ($this->type == 1) {
                $cash->balance += $this->amount; // доход
            } else {
                $cash->balance -= $this->amount; // расход
            }
            $cash->save();
        }
    }
}
