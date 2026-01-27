<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель корпоративных праздников
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property \Carbon\Carbon $date
 * @property bool $is_recurring
 * @property string $color
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Company $company
 */
class CompanyHoliday extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'date',
        'is_recurring',
        'color',
    ];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
    ];

    protected $attributes = [
        'color' => '#FF5733',
        'is_recurring' => true,
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
