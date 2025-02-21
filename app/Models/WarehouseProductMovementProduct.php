<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseProductMovementProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_id',
        'product_id',
        'quantity',
        'serial_number_id',
    ];

    public function movement()
    {
        return $this->belongsTo(WarehouseProductMovement::class, 'movement_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function serialNumber()
    {
        return $this->belongsTo(ProductSerialNumber::class, 'serial_number_id');
    }
}
