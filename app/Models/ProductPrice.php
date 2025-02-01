<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    protected $fillable = [
        'product_id',
        'retail_price',
        'wholesale_price',
        'purchase_price',
        'currency_id',
        // 'effective_date',
    ];

    protected $attributes = [
        'retail_price' => 0.0,
        'wholesale_price' => 0.0,
        'purchase_price' => 0.0,
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
