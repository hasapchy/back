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
 * @property bool $is_tr_visible Показывать проекты в списке для выбора
 * @property int $creator_id ID создателя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\User $creator
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Project[] $projects
 */
class ProjectStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'color',
        'is_tr_visible',
        'creator_id'
    ];

    protected $casts = [
        'is_tr_visible' => 'boolean',
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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('creator_id', $userId);
    }
}
