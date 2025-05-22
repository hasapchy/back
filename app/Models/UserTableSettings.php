<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTableSettings extends Model
{
    protected $fillable = ['user_id', 'table_name', 'order', 'visibility'];

    protected $casts = [
        'order' => 'array',
        'visibility' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
