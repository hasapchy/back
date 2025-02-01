<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderAf extends Model
{
    use HasFactory;

    protected $table = 'order_af';

    protected $fillable = [
        'name',
        'type',
        'category_ids',
        'required',
        'default',
        'user_id',
    ];
    

    protected $casts = [
        'category_ids' => 'array',
        'required' => 'boolean',
    ];

    
}
