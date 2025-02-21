<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseProductWriteOff extends Model
{
    use HasFactory;

    protected $fillable = ['warehouse_id', 'note'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function writeOffProducts()
    {
        return $this->hasMany(WarehouseProductWriteOffProduct::class, 'write_off_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouseStock()
    {
        return $this->hasOne(WarehouseStock::class, 'warehouse_id', 'warehouse_id');
    }
}
