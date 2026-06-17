<?php

namespace App\Models;

use App\Contracts\SupportsTimeline;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Activity;

class News extends Model implements SupportsTimeline
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'content',
        'company_id',
        'creator_id',
        'is_important',
        'meta',
    ];

    protected $casts = [
        'is_important' => 'boolean',
        'meta' => 'array',
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
    public function author()
    {
        return $this->creator();
    }

    /**
     * @return MorphMany<Comment>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return MorphMany<Activity>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * @return HasMany<NewsReaction>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(NewsReaction::class);
    }

    /**
     * @return HasMany<NewsAcknowledgement>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(NewsAcknowledgement::class);
    }

    /**
     * @return HasMany<NewsView>
     */
    public function views(): HasMany
    {
        return $this->hasMany(NewsView::class);
    }
}
