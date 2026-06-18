<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'restrict_visibility',
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
        'restrict_visibility' => 'boolean',
        'priority' => TaskPriority::class,
        'complexity' => TaskComplexity::class,
    ];

    protected $attributes = [
        'priority' => 'low',
        'complexity' => 'normal',
        'restrict_visibility' => true,
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function executor()
    {
        return $this->belongsTo(User::class, 'executor_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsToMany
     */
    public function observers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_observers', 'task_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function status()
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    /**
     * @return MorphMany
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(\App\Models\Comment::class, 'commentable');
    }

    /**
     * @return MorphMany
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    /**
     * @param User $user
     * @return bool
     */
    public function userCanView(User $user): bool
    {
        $userId = (int) $user->id;

        if ($this->isDirectParticipant($userId)) {
            return true;
        }

        if (! $this->restrict_visibility && $this->project_id) {
            return $this->userIsProjectParticipant($userId);
        }

        return false;
    }

    /**
     * @param User $user
     * @return bool
     */
    public function userCanUpdate(User $user): bool
    {
        return (int) $this->creator_id === (int) $user->id;
    }

    /**
     * @param int $userId
     * @return bool
     */
    public function isDirectParticipant(int $userId): bool
    {
        if (in_array($userId, [
            (int) $this->creator_id,
            (int) $this->supervisor_id,
            (int) $this->executor_id,
        ], true)) {
            return true;
        }

        if ($this->relationLoaded('observers')) {
            return $this->observers->contains(fn (User $observer) => (int) $observer->id === $userId);
        }

        return $this->observers()->where('users.id', $userId)->exists();
    }

    /**
     * @param int $userId
     * @return bool
     */
    public function userIsProjectParticipant(int $userId): bool
    {
        $project = $this->relationLoaded('project') ? $this->project : $this->project()->first();

        if (! $project) {
            return false;
        }

        return $project->hasParticipant($userId);
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
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'description', 'status_id', 'priority', 'complexity', 'deadline', 'supervisor_id', 'executor_id', 'project_id', 'restrict_visibility'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => $this->getDescriptionForEvent($eventName));
    }
}
