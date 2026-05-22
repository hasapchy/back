<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель корпоративных праздников
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property \Carbon\Carbon $date
 * @property \Carbon\Carbon|null $end_date
 * @property bool $is_recurring
 * @property string $color
 * @property string $icon
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Company $company
 */
class CompanyHoliday extends Model
{
    use BelongsToCompany;
    use HasFactory;

    /**
     * Whitelist FontAwesome classes for the holiday icon picker.
     * Mirrors `front/src/constants/holidayIconOptions.js`.
     */
    public const ALLOWED_ICONS = [
        'fa-solid fa-calendar-day',
        'fa-solid fa-gift',
        'fa-solid fa-champagne-glasses',
        'fa-solid fa-cake-candles',
        'fa-solid fa-flag',
        'fa-solid fa-heart',
        'fa-solid fa-star',
        'fa-solid fa-briefcase',
        'fa-solid fa-users',
        'fa-solid fa-bell',
        'fa-solid fa-sun',
        'fa-solid fa-snowflake',
    ];

    public const DEFAULT_ICON = self::ALLOWED_ICONS[0];

    protected $fillable = [
        'company_id',
        'name',
        'date',
        'end_date',
        'is_recurring',
        'color',
        'icon',
    ];

    protected $casts = [
        'date' => 'date',
        'end_date' => 'date',
        'is_recurring' => 'boolean',
    ];

    protected $attributes = [
        'color' => '#FF5733',
        'is_recurring' => true,
        'icon' => self::DEFAULT_ICON,
    ];
}
