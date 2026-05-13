<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель единицы измерения
 *
 * @property int $id
 * @property int|null $company_id null — системная единица из сидера, не изменяется через API настроек
 * @property string $name Название единицы измерения
 * @property string $short_name Краткое название
 *
 * @method static Builder|static forCompanyCatalog(?int $companyId)
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Product[] $products
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\UnitConversion[] $conversionsAsParent
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\UnitConversion[] $conversionsAsChild
 */
class Unit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'name',
        'short_name',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'unit_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function conversionsAsParent()
    {
        return $this->hasMany(UnitConversion::class, 'parent_unit_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function conversionsAsChild()
    {
        return $this->hasMany(UnitConversion::class, 'child_unit_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return bool
     */
    public function isSystemUnit(): bool
    {
        return $this->company_id === null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompanyCatalog(Builder $query, ?int $companyId): Builder
    {
        $query->orderByRaw('company_id is null desc')->orderBy('id');
        if ($companyId) {
            $query->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            });
        } else {
            $query->whereNull('company_id');
        }

        return $query;
    }
}
