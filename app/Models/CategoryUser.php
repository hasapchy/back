<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель связи категории и пользователя
 *
 * @property int $id
 * @property int $category_id ID категории
 * @property int $user_id ID пользователя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Category $category
 * @property-read \App\Models\User $user
 */
class CategoryUser extends Model
{
    use HasFactory;

    protected $table = 'category_users';

    protected $fillable = ['category_id', 'user_id'];

    /**
     * Связь с категорией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Связь с пользователем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
