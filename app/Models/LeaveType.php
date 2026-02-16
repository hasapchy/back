<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Leave;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'color', 'is_penalty'];

    protected $casts = [
        'is_penalty' => 'boolean',
    ];

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }
}
