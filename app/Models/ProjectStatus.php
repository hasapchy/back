<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель статуса проекта
 *
 * @property int $id
 * @property string $name Название статуса
 * @property string $color Цвет статуса
 * @property int $user_id ID пользователя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Project[] $projects
 */
class ProjectStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'color',
        'user_id'
    ];

    /**
     * Связь с проектами
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function projects()
    {
        return $this->hasMany(Project::class, 'status_id');
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

    /**
     * Scope для фильтрации по пользователю
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId ID пользователя
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
