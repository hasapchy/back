<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhWriteoffProduct extends Model
{
    use HasFactory;
    protected $table = 'wh_write_off_products';
    protected $fillable = ['write_off_id', 'product_id', 'quantity', 'sn_id'];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function writeOff()
    {
        return $this->belongsTo(WhWriteoff::class, 'write_off_id');
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
