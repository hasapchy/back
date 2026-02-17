<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель комментария
 *
 * @property int $id
 * @property string $body Текст комментария
 * @property int $creator_id ID создателя
 * @property int $commentable_id ID комментируемой сущности
 * @property string $commentable_type Тип комментируемой сущности
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Model $commentable
 * @property-read \App\Models\User $creator
 */
class Comment extends Model
{
    use HasFactory;

    protected $fillable = ['body', 'creator_id', 'commentable_id', 'commentable_type'];

    /**
     * Morphable связь с комментируемой сущностью
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function commentable()
    {
        return $this->morphTo();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
