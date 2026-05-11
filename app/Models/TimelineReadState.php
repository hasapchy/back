<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimelineReadState extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'commentable_type',
        'commentable_id',
        'last_read_comment_id',
        'last_read_at',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, TimelineReadState>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, TimelineReadState>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
