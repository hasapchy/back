<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhMovementProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_id',
        'product_id',
        'quantity',
        'sn_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function movement()
    {
        return $this->belongsTo(WhMovement::class, 'movement_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function serialNumber()
    {
        return $this->belongsTo(ProductSerialNumber::class, 'sn_id');
    }
}
