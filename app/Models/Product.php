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
        'images',
        'category_id',
        'stock_quantity',
        'status_id',
        'barcode',
        'is_serialized',
        'type',
    ];

    protected $casts = [
        'images' => 'array',
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
        return $this->hasMany(WarehouseProductReceiptProduct::class);
    }

    public function writeOffProducts()
    {
        return $this->hasMany(WarehouseProductWriteOffProduct::class);
    }

    public function movementProducts()
    {
        return $this->hasMany(WarehouseProductMovementProduct::class);
    }

    public function salesProducts()
    {
        return $this->hasMany(SalesProduct::class);
    }
}
