<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ProductionCalendarDay extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function getTable()
    {
        if (Schema::hasTable('production_calendar_days')) {
            return 'production_calendar_days';
        }

        return 'company_production_calendar_days';
    }
}
