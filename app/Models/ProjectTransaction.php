<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'type',
        'amount',
        'currency_id',
        'category_id',
        'note',
        'date'
    ];

    protected $casts = [
        'date' => 'datetime',
        'type' => 'boolean',
        'amount' => 'decimal:2'
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function category()
    {
        return $this->belongsTo(TransactionCategory::class);
    }
}
