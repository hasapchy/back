<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'category_id'];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($category) {
            $protectedIds = [1, 2, 3];
            if (in_array($category->id, $protectedIds)) {
                return false;
            }
        });
    }

    public function category()
    {
        return $this->belongsTo(OrderStatusCategory::class, 'category_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'status_id');
    }
}
