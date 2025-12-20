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
 * @property int $user_id ID пользователя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 */
class TransactionCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'user_id',
    ];

    protected $casts = [
        'type' => 'integer',
    ];

    protected static $protectedCategoryIds = [1, 2, 3, 4, 5, 6, 7, 14, 17];

    /**
     * Связь с пользователем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
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
            throw new \Exception('Нельзя удалить системную категорию');
        }

        return parent::delete();
    }
}
