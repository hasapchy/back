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
    ];

    protected $casts = [
        'retail_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
    ];

    protected $attributes = [
        'retail_price' => 0.0,
        'wholesale_price' => 0.0,
        'purchase_price' => 0.0,
    ];

}
