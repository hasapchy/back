<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhWriteoff extends Model
{
    use HasFactory;
    protected $table = 'wh_write_offs';
    
    protected $fillable = ['warehouse_id', 'note', 'date', 'user_id'];
  

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function writeOffProducts()
    {
        return $this->hasMany(WhWriteoffProduct::class, 'write_off_id');
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
