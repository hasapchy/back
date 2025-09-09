<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\CurrencyConverter;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use App\Models\OrderTransaction;
use App\Models\WhReceipt;

class Transaction extends Model
{
    use HasFactory, LogsActivity;
    protected $skipClientBalanceUpdate = false;
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

    protected $hidden = [
        'skipClientBalanceUpdate',
    ];

    public function setSkipClientBalanceUpdate($value)
    {
        $this->skipClientBalanceUpdate = $value;
    }

    public function getSkipClientBalanceUpdate()
    {
        return $this->skipClientBalanceUpdate;
    }

    protected static function booted()
    {
        static::creating(function ($transaction) {
            // Если передали кастомный флаг no_balance_update = true
            if (!empty($transaction->no_balance_update)) {
                $transaction->setSkipClientBalanceUpdate(true);
            }
        });

        static::created(function ($transaction) {
            if (
                $transaction->client_id
                && !$transaction->getSkipClientBalanceUpdate()
            ) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $transaction->client_id]);
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

                if ($transaction->type == 1) {
                    $clientBalance->balance -= $convertedAmount;
                } else {
                    $clientBalance->balance += $convertedAmount;
                }
                $clientBalance->save();
            }
        });

        static::updated(function ($transaction) {
            if ($transaction->client_id && empty($transaction->getSkipClientBalanceUpdate())) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $transaction->client_id]);
                $defaultCurrency = Currency::where('is_default', true)->first();

                $originalAmount = $transaction->getOriginal('amount');
                $originalCurrency = Currency::find($transaction->getOriginal('currency_id'));
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

                if ($transaction->type == 1) {
                    $clientBalance->balance = $clientBalance->balance - $originalConverted + $currentConverted;
                } else {
                    $clientBalance->balance = $clientBalance->balance + $originalConverted - $currentConverted;
                }
                $clientBalance->save();
            }
        });

        static::deleted(function ($transaction) {
            if ($transaction->client_id && empty($transaction->getSkipClientBalanceUpdate())) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $transaction->client_id]);
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

                if ($transaction->type == 1) {
                    $clientBalance->balance -= $convertedAmount;
                } else {
                    $clientBalance->balance += $convertedAmount;
                }
                $clientBalance->save();
            }
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

    public function orders()
    {
        return $this->belongsToMany(Order::class, 'order_transactions', 'transaction_id', 'order_id');
    }

    public function orderTransactions()
    {
        return $this->hasMany(OrderTransaction::class, 'transaction_id');
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'transaction_id');
    }

    public function warehouseReceipts()
    {
        return $this->hasMany(WhReceipt::class, 'transaction_id');
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
}
