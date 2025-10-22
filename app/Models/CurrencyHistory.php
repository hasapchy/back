<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyHistory extends Model
{
    protected $fillable = [
        'currency_id',
        'exchange_rate',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:4',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
