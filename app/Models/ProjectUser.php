<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель связи проекта и пользователя
 *
 * @property int $id
 * @property int $project_id ID проекта
 * @property int $user_id ID пользователя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Project $project
 * @property-read \App\Models\User $user
 */
class ProjectUser extends Model
{
    use HasFactory;

    protected $table = 'project_users';

    protected $fillable = ['project_id', 'user_id'];

    /**
     * Связь с проектом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
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
