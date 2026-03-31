<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель правила округления компании
 *
 * @property int $id
 * @property int $company_id ID компании
 * @property string $context Контекст округления
 * @property int $decimals Количество знаков после запятой
 * @property string $direction Направление округления
 * @property float|null $custom_threshold Порог для кастомного округления
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Company $company
 */
class CompanyRoundingRule extends Model
{
    use BelongsToCompany;
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

}
