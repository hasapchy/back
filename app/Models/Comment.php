<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель комментария
 *
 * @property int $id
 * @property string $body Текст комментария
 * @property int|null $parent_id ID родительского комментария
 * @property int $creator_id ID создателя
 * @property int $commentable_id ID комментируемой сущности
 * @property string $commentable_type Тип комментируемой сущности
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Model $commentable
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\Comment|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Comment> $replies
 */
class Comment extends Model
{
    use HasFactory;

    protected $fillable = ['body', 'creator_id', 'parent_id', 'commentable_id', 'commentable_type'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function commentable()
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, Comment>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return BelongsTo<Comment, Comment>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Comment>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    /**
     * @return HasMany<CommentReaction>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }
}
