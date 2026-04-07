<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Category;
use App\Models\ProductPrice;
use App\Models\WarehouseStock;
use App\Models\WhReceiptProduct;
use App\Models\WhWriteoffProduct;
use App\Models\WhMovementProduct;
use App\Models\SalesProduct;
use App\Models\Unit;
use App\Models\Comment;
use App\Contracts\SupportsTimeline;

/**
 * Модель продукта
 *
 * @property int $id
 * @property string $name Название продукта
 * @property string|null $description Описание
 * @property string|null $sku Артикул
 * @property string|null $image Изображение
 * @property int $unit_id ID единицы измерения
 * @property string|null $barcode Штрих-код
 * @property bool $is_serialized Является ли серийным
 * @property bool $type Тип продукта (0 - товар, 1 - услуга)
 * @property \Carbon\Carbon|null $date Дата
 * @property int $creator_id ID пользователя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Category[] $categories
 * @property-read \App\Models\Unit $unit
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ProductPrice[] $prices
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WarehouseStock[] $stocks
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhReceiptProduct[] $receiptProducts
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhWriteoffProduct[] $writeOffProducts
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhMovementProduct[] $movementProducts
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\SalesProduct[] $salesProducts
 * @property-read \App\Models\User $creator
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Comment[] $comments
 */
class Product extends Model implements SupportsTimeline
{
    use HasFactory;

    protected $table = 'products';
    protected $fillable = [
        'name',
        'description',
        'sku',
        'image',
        'unit_id',
        'barcode',
        'is_serialized',
        'type',
        'date',
        'creator_id',
    ];

    protected $casts = [
        'is_serialized' => 'boolean',
        'type' => 'boolean',
        'date' => 'datetime',
    ];

    /**
     * Связь с множественными категориями через промежуточную таблицу
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'product_categories', 'product_id', 'category_id')
            ->withTimestamps();
    }

    /**
     * Связь с единицей измерения
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Связь с ценами продукта
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function prices()
    {
        return $this->hasMany(ProductPrice::class, 'product_id');
    }

    /**
     * Связь со складскими остатками
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function stocks()
    {
        return $this->hasMany(WarehouseStock::class, 'product_id');
    }

    /**
     * Связь с продуктами приходов
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function receiptProducts()
    {
        return $this->hasMany(WhReceiptProduct::class, 'product_id');
    }

    /**
     * Связь с продуктами списаний
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function writeOffProducts()
    {
        return $this->hasMany(WhWriteoffProduct::class, 'product_id');
    }

    /**
     * Связь с продуктами перемещений
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function movementProducts()
    {
        return $this->hasMany(WhMovementProduct::class, 'product_id');
    }

    /**
     * Связь с продуктами продаж
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function salesProducts()
    {
        return $this->hasMany(SalesProduct::class, 'product_id');
    }

    /**
     * Связь с создателем продукта
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $companyId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCompany($query, $companyId = null)
    {
        if ($companyId) {
            return $query->whereHas('categories', function ($q) use ($companyId) {
                $q->where('categories.company_id', $companyId);
            });
        }

        return $query;
    }

    /**
     * Scope для фильтрации по типу продукта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $type Тип продукта (0 - товар, 1 - услуга)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, $type = null)
    {
        if ($type !== null) {
            return $query->where('type', $type);
        }
        return $query;
    }
}
