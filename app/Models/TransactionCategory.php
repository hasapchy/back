<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель категории транзакции
 *
 * @property int $id
 * @property string $name Название категории
 * @property int $type Тип категории (0 - расход, 1 - доход)
 * @property int|null $parent_id ID родительской категории
 * @property int $creator_id ID создателя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\TransactionCategory|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\TransactionCategory[] $children
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 */
class TransactionCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'creator_id',
        'parent_id',
    ];

    protected $casts = [
        'type' => 'integer',
    ];

    protected static $protectedCategoryIds = [1, 2, 3, 4, 5, 6, 7, 14, 17];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(TransactionCategory::class, 'parent_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function children()
    {
        return $this->hasMany(TransactionCategory::class, 'parent_id');
    }

    /**
     * Связь с транзакциями
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'category_id');
    }

    /**
     * Проверить, можно ли удалить категорию
     *
     * @return bool
     */
    public function canBeDeleted()
    {
        return !in_array($this->id, self::$protectedCategoryIds);
    }

    /**
     * Проверить, можно ли редактировать категорию
     *
     * @return bool
     */
    public function canBeEdited()
    {
        return !in_array($this->id, self::$protectedCategoryIds);
    }

    /**
     * Удалить категорию (с проверкой на системные категории)
     *
     * @return bool|null
     * @throws \Exception
     */
    public function delete()
    {
        if (!$this->canBeDeleted()) {
            throw new \Exception('Нельзя удалить системную категорию: ' . $this->name);
        }

        return parent::delete();
    }
}
