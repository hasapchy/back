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
        'cash_register_id',
        'transaction_date',
        'transaction_id',
        'currency_id',
        'user_id',
        'warehouse_id',
        'discount_price',
        'price',
        'project_id'
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
