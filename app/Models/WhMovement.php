<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'wh_from',
        'wh_to',
        'note',
        'date',
    ];

    public function warehouseFrom()
    {
        return $this->belongsTo(Warehouse::class, 'wh_from');
    }

    public function warehouseTo()
    {
        return $this->belongsTo(Warehouse::class, 'wh_to');
    }

    public function products()
    {
        return $this->hasMany(WhMovementProduct::class, 'movement_id');
    }
}
