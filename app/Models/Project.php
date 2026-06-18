<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasManyToManyUsers;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use App\Contracts\SupportsTimeline;

/**
 * Модель проекта
 *
 * @property int $id
 * @property string $name Название проекта
 * @property int $creator_id ID создателя проекта
 * @property int $client_id ID клиента
 * @property float $budget Бюджет проекта
 * @property int|null $currency_id ID валюты
 * @property \Carbon\Carbon|null $date Дата проекта
 * @property string|null $description Описание
 * @property int $status_id ID статуса проекта
 * @property int|null $company_id ID компании
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\Currency|null $currency
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ProjectUser[] $projectUsers
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 * @property-read \App\Models\ProjectStatus $status
 * @property-read \App\Models\Company|null $company
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ProjectContract[] $contracts
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Comment[] $comments
 * @property-read \App\Models\DriveFolder|null $driveFolder
 */
class Project extends Model implements SupportsTimeline
{
    use BelongsToCompany;
    use HasFactory, HasManyToManyUsers, LogsActivity;

    protected $fillable = ['name', 'creator_id', 'client_id', 'budget', 'currency_id', 'date', 'description', 'status_id', 'company_id'];

    protected $casts = [
        'date' => 'datetime',
        'budget' => 'decimal:5',
    ];

    /**
     * @return string
     */
    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created', 'updated', 'deleted' => "activity_log.project.{$eventName}",
            default => 'activity_log.project.default',
        };
    }

    /**
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'budget', 'currency_id', 'date', 'client_id', 'description', 'status_id'])
            ->useLogName('project')
            ->dontSubmitEmptyLogs()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    /**
     * Связь с клиентом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Связь с создателем проекта
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Связь с валютой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Связь с транзакциями проекта
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'project_id');
    }

    /**
     * Связь с пользователями проекта через промежуточную таблицу
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function projectUsers()
    {
        return $this->hasMany(ProjectUser::class, 'project_id');
    }

    /**
     * Связь many-to-many с пользователями
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'project_users', 'project_id', 'user_id');
    }

    /**
     * Связь со статусом проекта
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function status()
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    /**
     * Связь с контрактами проекта
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function contracts()
    {
        return $this->hasMany(ProjectContract::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function chat()
    {
        return $this->hasOne(Chat::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function driveFolder()
    {
        return $this->hasOne(DriveFolder::class, 'project_id');
    }

    /**
     * @param int $userId
     * @return bool
     */
    public function hasParticipant(int $userId): bool
    {
        if ((int) $this->creator_id === $userId) {
            return true;
        }

        if ($this->relationLoaded('users')) {
            return $this->users->contains(fn (User $user) => (int) $user->id === $userId);
        }

        return $this->hasUser($userId);
    }

    /**
     * @param User $user
     * @return bool
     */
    public function userCanView(User $user): bool
    {
        return $this->hasParticipant((int) $user->id);
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
     * @param  int|null  $currencyId
     * @return bool
     */
    public function canChangeCurrencyTo(?int $currencyId): bool
    {
        if (! $this->contracts()->exists()) {
            return true;
        }

        $current = $this->currency_id !== null ? (int) $this->currency_id : null;
        $next = $currencyId !== null ? (int) $currencyId : null;

        return $current === $next;
    }
}
