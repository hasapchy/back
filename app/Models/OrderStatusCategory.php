<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderStatusCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'user_id', 'color'];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($category) {
            $protectedIds = [1, 2, 3, 4, 5];
            if (in_array($category->id, $protectedIds)) {
                return false;
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function statuses()
    {
        return $this->hasMany(OrderStatus::class, 'category_id');
    }
}
