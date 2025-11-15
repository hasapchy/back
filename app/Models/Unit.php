<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель единицы измерения
 *
 * @property int $id
 * @property string $name Название единицы измерения
 * @property string $short_name Краткое название
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Product[] $products
 */
class Unit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'short_name'
    ];

    /**
     * Связь с продуктами
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'unit_id');
    }
}
