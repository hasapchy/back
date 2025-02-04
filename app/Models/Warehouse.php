<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\WarehouseStock;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'users'];

    protected $casts = [
        'users' => 'array',
    ];

    public function stocks()
    {
        return $this->hasMany(WarehouseStock::class);
    }
}
