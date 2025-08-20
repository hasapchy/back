<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'category_id');
    }

    public function additionalFields()
    {
        return $this->belongsToMany(OrderAf::class, 'order_af_categories', 'order_category_id', 'order_af_id');
    }

    public function getAdditionalFields()
    {
        return $this->additionalFields()->orderBy('name')->get();
    }
}
