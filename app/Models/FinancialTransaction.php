<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'amount',
        'cash_register_id',
        'note',
        'transaction_date',
        'currency_id',
        'category_id',
        'user_id',
        'client_id',
        'project_id',
    ];

    protected static function booted()
    {
        static::created(function ($transaction) {
            if ($transaction->client_id) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $transaction->client_id]);
                if ($transaction->type == 1) { // 1 for income
                    $clientBalance->balance -= $transaction->amount; // Client gave money, decrease balance
                } else { // 0 for expense
                    $clientBalance->balance += $transaction->amount; // Client received money, increase balance
                }
                $clientBalance->save();
            }
        });

        static::updated(function ($transaction) {
            if ($transaction->client_id) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $transaction->client_id]);
                $originalAmount = $transaction->getOriginal('amount');
                if ($transaction->type == 1) { // 1 for income
                    $clientBalance->balance += $originalAmount; // Revert original amount
                    $clientBalance->balance -= $transaction->amount; // Apply new amount
                } else { // 0 for expense
                    $clientBalance->balance -= $originalAmount; // Revert original amount
                    $clientBalance->balance += $transaction->amount; // Apply new amount
                }
                $clientBalance->save();
            }
        });

        static::deleted(function ($transaction) {
            if ($transaction->client_id) {
                $clientBalance = ClientBalance::firstOrCreate(['client_id' => $transaction->client_id]);
                if ($transaction->type == 1) { // 1 for income
                    $clientBalance->balance += $transaction->amount; // Revert income
                } else { // 0 for expense
                    $clientBalance->balance -= $transaction->amount; // Revert expense
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
