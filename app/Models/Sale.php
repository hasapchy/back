<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'warehouse_id',
        'total_amount',
        'note',
        'currency_id',
        'cash_register_id',
        'transaction_date',
        'currency_id',
        'user_id',
        'warehouse_id',
        'discount_price',
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
