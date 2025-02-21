<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_id',
        'client_id',
        'currency_id',
        'date',
        'discount',
        'note',
        'orig_currency_id',
        'orig_price',
        'price',
        'project_id',
        'total_price',
        'transaction_id',
        'user_id',
        'warehouse_id'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'sales_products')
            ->withPivot('quantity', 'price', 'price_with_discount',);
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }
}
