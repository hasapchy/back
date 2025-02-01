<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_cash_register_id',
        'to_cash_register_id',
        'amount',
        'note',
        'transfer_date',
        'currency_id',
    ];

    public function fromCashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'from_cash_register_id');
    }

    public function toCashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'to_cash_register_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
