<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_cash_register_id',
        'to_cash_register_id',
        'from_transaction_id',
        'to_transaction_id',
        'user_id',
        'amount',
        'note',
    ];

    public function fromCashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'from_cash_register_id');
    }

    public function toCashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'to_cash_register_id');
    }

    public function fromTransaction()
    {
        return $this->belongsTo(Transaction::class, 'from_transaction_id');
    }

    public function toTransaction()
    {
        return $this->belongsTo(Transaction::class, 'to_transaction_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
