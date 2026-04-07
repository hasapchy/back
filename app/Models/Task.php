<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use App\Enums\TaskPriority;
use App\Enums\TaskComplexity;
use App\Contracts\SupportsTimeline;

class Task extends Model implements SupportsTimeline
{
    use BelongsToCompany;
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
        'priority',
        'complexity',
        'deadline',
        'files',
        'comments',
        'checklist'
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'files' => 'array',
        'comments' => 'array',
        'checklist' => 'array',
        'priority' => TaskPriority::class,
        'complexity' => TaskComplexity::class,
    ];

    protected $attributes = [
        'priority' => 'low',
        'complexity' => 'normal',
    ];

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
    public function comments(): MorphMany
    {
        return $this->morphMany(\App\Models\Comment::class, 'commentable');
    }

    /**
     * Связь с активностями (activity log)
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    /**
     * @param string $eventName
     * @return string
     */
    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created', 'updated', 'deleted' => "activity_log.task.{$eventName}",
            default => 'activity_log.task.default',
        };
    }

    /**
     * Настройки логирования активности
     *
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'description', 'status_id', 'priority', 'complexity', 'deadline', 'supervisor_id', 'executor_id', 'project_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => $this->getDescriptionForEvent($eventName));
    }
}
