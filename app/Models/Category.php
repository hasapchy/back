<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasManyToManyUsers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель категории
 *
 * @property int $id
 * @property string $name Название категории
 * @property int|null $parent_id ID родительской категории
 * @property int $creator_id ID создателя
 * @property int|null $company_id ID компании
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Category|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Category[] $children
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\CategoryUser[] $categoryUsers
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 * @property-read \App\Models\Company|null $company
 *
 * @property string|null $creator_name
 */
class Category extends Model
{
    use BelongsToCompany;
    use HasFactory, HasManyToManyUsers;

    protected $fillable = ['name', 'parent_id', 'creator_id', 'company_id'];

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
     * @return array<int, int>
     */
    public static function descendantIdsIncludingRoot(int $rootId): array
    {
        $ids = [$rootId];
        $queue = [$rootId];

        while ($queue !== []) {
            $parentId = array_shift($queue);
            $childIds = static::query()
                ->where('parent_id', $parentId)
                ->pluck('id');

            foreach ($childIds as $cid) {
                $cid = (int) $cid;
                $ids[] = $cid;
                $queue[] = $cid;
            }
        }

        return array_values(array_unique($ids));
    }
}
