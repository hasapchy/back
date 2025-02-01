<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseProductMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_from',
        'warehouse_to',
        'note',
    ];

    public function warehouseFrom()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_from');
    }

    public function warehouseTo()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_to');
    }

    public function products()
    {
        return $this->hasMany(WarehouseProductMovementProduct::class, 'movement_id');
    }
}
