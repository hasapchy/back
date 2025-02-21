<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'client_id',
        'user_id',
        'status_id',
        'category_id',
        'transaction_ids',
        'note',
        'date',
    ];

    protected $casts = [
        'transaction_ids' => 'array', 
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function status()
    {
        return $this->belongsTo(OrderStatus::class);
    }

    public function category()
    {
        return $this->belongsTo(OrderCategory::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'id', 'transaction_ids');
    }

    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class);
    }
}
