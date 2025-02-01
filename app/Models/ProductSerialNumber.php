<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductSerialNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'serial_number',
        'status_id',
        'warehouse_id',
        // 'warehouse_product_receipt_product_id', // Removed field
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function status()
    {
        return $this->belongsTo(ProductStatus::class);
    }

    // Remove the receiptProduct relationship
    // public function receiptProduct()
    // {
    //     return $this->belongsTo(WarehouseProductReceiptProduct::class, 'warehouse_product_receipt_product_id');
    // }

}
