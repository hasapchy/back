<?php

namespace App\Contracts;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Activity;

interface SupportsTimeline
{
    /**
     * @return MorphMany<Comment>
     */
    public function comments(): MorphMany;

    /**
     * @return MorphMany<Activity>
     */
    public function activities(): MorphMany;
}
