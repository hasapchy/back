<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;
    protected $table = 'products';
    protected $fillable = [
        'name',
        'description',
        'sku',
        'image',
        'category_id',
        'stock_quantity',
        'unit_id',
        'status_id',
        'barcode',
        'is_serialized',
        'type',
    ];

    protected $casts = [
        'is_serialized' => 'boolean',
        'type' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }


    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }


    public function stocks()
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function status()
    {
        return $this->belongsTo(ProductStatus::class, 'status_id');
    }

    public function receiptProducts()
    {
        return $this->hasMany(WhReceiptProduct::class);
    }

    public function writeOffProducts()
    {
        return $this->hasMany(WhWriteoffProduct::class);
    }

    public function movementProducts()
    {
        return $this->hasMany(WhMovementProduct::class);
    }

    public function salesProducts()
    {
        return $this->hasMany(SalesProduct::class);
    }
}
