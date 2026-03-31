<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CompanyProductionCalendarDay extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
