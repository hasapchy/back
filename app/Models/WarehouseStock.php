<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseStock extends Model
{
    use HasFactory;

    protected $fillable = ['warehouse_id', 'product_id', 'quantity'];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function writeOffs()
    {
        return $this->hasMany(WhWriteoff::class, 'warehouse_id', 'warehouse_id');
    }
}
