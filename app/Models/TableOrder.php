<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TableOrder extends Model
{
    protected $fillable = [
        'user_id',
        'table_name',
        'order',
    ];
}
