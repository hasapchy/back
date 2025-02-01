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

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
