<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyRoundingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'context',
        'decimals',
        'direction',
        'custom_threshold',
    ];

    protected $casts = [
        'decimals' => 'integer',
        'custom_threshold' => 'decimal:2',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}


