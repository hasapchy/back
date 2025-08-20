<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class OrderAf extends Model
{
    use HasFactory;

    protected $table = 'order_af';

    protected $fillable = [
        'name',
        'type',
        'options',
        'required',
        'default',
        'user_id',
    ];


    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
    ];

    public function categories()
    {
        return $this->belongsToMany(OrderCategory::class, 'order_af_categories', 'order_af_id', 'order_category_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function values()
    {
        return $this->hasMany(OrderAfValue::class, 'order_af_id');
    }

    public function isSelect()
    {
        return $this->type === 'select';
    }

    public function isBoolean()
    {
        return $this->type === 'boolean';
    }

    public function isInt()
    {
        return $this->type === 'int';
    }

    public function isString()
    {
        return $this->type === 'string';
    }

    public function isDate()
    {
        return $this->type === 'date';
    }

    public function isDatetime()
    {
        return $this->type === 'datetime';
    }

    public function getOptionsArray()
    {
        return $this->options ?? [];
    }
}
