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
        'cash_id',
        'amount',
        'date',
        'user_id',
        'project_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Client::class, 'supplier_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
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

    // Morphable связь с транзакциями (новая архитектура)
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
