<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_id_from',
        'cash_id_to',
        'tr_id_from',
        'tr_id_to',
        'user_id',
        'amount',
        'note',
        'date',
    ];

    public function fromCashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id_from');
    }

    public function toCashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id_to');
    }

    public function fromTransaction()
    {
        return $this->belongsTo(Transaction::class, 'tr_id_from');
    }

    public function toTransaction()
    {
        return $this->belongsTo(Transaction::class, 'tr_id_to');
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
