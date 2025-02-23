<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhReceiptProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'receipt_id',
        'product_id',
        'quantity',
        'sn_id',
        'price',
    ];

    public function receipt()
    {
        return $this->belongsTo(WhReceipt::class, 'receipt_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function serialNumbers()
    {
        return $this->hasMany(ProductSerialNumber::class, 'sn_id');
    }
}