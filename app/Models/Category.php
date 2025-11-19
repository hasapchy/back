<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель категории
 *
 * @property int $id
 * @property string $name Название категории
 * @property int|null $parent_id ID родительской категории
 * @property int $user_id ID пользователя
 * @property int|null $company_id ID компании
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Category|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Category[] $children
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\CategoryUser[] $categoryUsers
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 * @property-read \App\Models\Company|null $company
 */
class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'parent_id', 'user_id', 'company_id'];

    /**
     * Связь с дочерними категориями
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Связь с родительской категорией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Связь с пользователями категории через промежуточную таблицу
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function categoryUsers()
    {
        return $this->hasMany(CategoryUser::class, 'category_id');
    }

    /**
     * Связь many-to-many с пользователями
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'category_users', 'category_id', 'user_id');
    }

    /**
     * Accessor для получения пользователей
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUsersAttribute()
    {
        $relation = $this->getRelationValue('users');

        if ($relation !== null) {
            return $relation;
        }

        return $this->users()->get();
    }

    /**
     * Проверить, есть ли у категории пользователь
     *
     * @param int $userId ID пользователя
     * @return bool
     */
    public function hasUser($userId)
    {
        return $this->users()->where('user_id', $userId)->exists();
    }

    /**
     * Связь с компанией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
