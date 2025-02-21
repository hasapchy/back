<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;
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
        'orig_currency_id',
        'project_id',
        'type',
        'user_id',
    ];

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
        static::created(function ($transaction) {
            if ($transaction->client_id && empty($transaction->getSkipClientBalanceUpdate())) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $transaction->client_id]);
                $defaultCurrency = \App\Models\Currency::where('is_default', true)->first();
                // Если валюта транзакции не дефолтная, конвертируем сумму
                if ($transaction->currency_id != $defaultCurrency->id) {
                    $convertedAmount = \App\Services\CurrencyConverter::convert(
                        $transaction->amount,
                        $transaction->currency,
                        $defaultCurrency
                    );
                } else {
                    $convertedAmount = $transaction->amount;
                }

                if ($transaction->type == 1) {
                    $clientBalance->balance += $convertedAmount;
                } else {
                    $clientBalance->balance -= $convertedAmount;
                }
                $clientBalance->save();
            }
        });

        static::updated(function ($transaction) {
            if ($transaction->client_id && empty($transaction->getSkipClientBalanceUpdate())) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $transaction->client_id]);
                $defaultCurrency = \App\Models\Currency::where('is_default', true)->first();

                // Старая сумма и старая валюта
                $originalAmount = $transaction->getOriginal('amount');
                $originalCurrency = \App\Models\Currency::find($transaction->getOriginal('currency_id'));
                if ($transaction->getOriginal('currency_id') != $defaultCurrency->id) {
                    $originalConverted = \App\Services\CurrencyConverter::convert(
                        $originalAmount,
                        $originalCurrency,
                        $defaultCurrency
                    );
                } else {
                    $originalConverted = $originalAmount;
                }

                // Текущая сумма и валюта
                if ($transaction->currency_id != $defaultCurrency->id) {
                    $currentConverted = \App\Services\CurrencyConverter::convert(
                        $transaction->amount,
                        $transaction->currency,
                        $defaultCurrency
                    );
                } else {
                    $currentConverted = $transaction->amount;
                }

                // Корректировка баланса: отменяем эффект старой транзакции и применяем новый
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
                $defaultCurrency = \App\Models\Currency::where('is_default', true)->first();
                if ($transaction->currency_id != $defaultCurrency->id) {
                    $convertedAmount = \App\Services\CurrencyConverter::convert(
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
        return $this->belongsTo(CashRegister::class);
    }

    public function category()
    {
        return $this->belongsTo(TransactionCategory::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Client::class, 'supplier_id')->where('is_supplier', true);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->belongsToMany(Order::class, 'order_transaction', 'transaction_id', 'order_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function getExchangeRateAttribute()
    {
        $currency = $this->currency;
        if (!$currency) {
            return null;
        }

        $rateHistory = $currency->exchangeRateHistories()
            ->where('start_date', '<=', $this->transaction_date)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $this->transaction_date);
            })
            ->orderBy('start_date', 'desc')
            ->first();

        return $rateHistory ? $rateHistory->exchange_rate : null;
    }
}
