<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Category;
use App\Models\ProductPrice;
use App\Models\WarehouseStock;
use App\Models\ProductStatus;
use App\Models\WhReceiptProduct;
use App\Models\WhWriteoffProduct;
use App\Models\WhMovementProduct;
use App\Models\SalesProduct;
use App\Models\Unit;

class Product extends Model
{
    use HasFactory;
    protected $table = 'products';
    protected $fillable = [
        'name',
        'description',
        'sku',
        'image',
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


    /**
     * Связь с множественными категориями через промежуточную таблицу
     */
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'product_categories', 'product_id', 'category_id')
            ->withTimestamps();
    }


    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }


    public function prices()
    {
        return $this->hasMany(ProductPrice::class, 'product_id');
    }


    public function stocks()
    {
        return $this->hasMany(WarehouseStock::class, 'product_id');
    }

    public function status()
    {
        return $this->belongsTo(ProductStatus::class, 'status_id');
    }

    public function receiptProducts()
    {
        return $this->hasMany(WhReceiptProduct::class, 'product_id');
    }

    public function writeOffProducts()
    {
        return $this->hasMany(WhWriteoffProduct::class, 'product_id');
    }

    public function movementProducts()
    {
        return $this->hasMany(WhMovementProduct::class, 'product_id');
    }

    public function salesProducts()
    {
        return $this->hasMany(SalesProduct::class, 'product_id');
    }
}
