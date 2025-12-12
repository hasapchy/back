<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Task extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

     protected $fillable = [
        'title',
        'description',
        'creator_id',
        'supervisor_id',
        'executor_id',
        'project_id',
        'company_id',
        'status',
        'deadline',
        'files',
        'comments'
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'files' => 'array',
        'comments' => 'array'
    ];

    // Константы статусов
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_POSTPONED = 'postponed';

    // Связи
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function executor()
    {
        return $this->belongsTo(User::class, 'executor_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Связь с комментариями
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function comments()
    {
        return $this->morphMany(\App\Models\Comment::class, 'commentable');
    }

    /**
     * Связь с активностями (activity log)
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function activities()
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    /**
     * Настройки логирования активности
     *
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'description', 'status', 'deadline', 'supervisor_id', 'executor_id', 'project_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
