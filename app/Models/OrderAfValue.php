<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderAfValue extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'order_af_id', 'value'];
}
