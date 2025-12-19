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
        'status_id',
        'deadline',
        'files',
        'comments'
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'files' => 'array',
        'comments' => 'array'
    ];

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
     * Связь со статусом задачи
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function status()
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
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
            ->logOnly(['title', 'description', 'status_id', 'deadline', 'supervisor_id', 'executor_id', 'project_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
