<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'note',
        'currency_id',
        'amount',
        'date',
    ];

    public function supplier()
    {
        return $this->belongsTo(Client::class, 'supplier_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function products()
    {
        return $this->hasMany(WhReceiptProduct::class, 'receipt_id');
    }

    public function productsPivot()
    {
        return $this->belongsToMany(
            Product::class,
            'wh_receipt_products',
            'receipt_id',
            'product_id'
        )->withPivot('quantity');
    }
}