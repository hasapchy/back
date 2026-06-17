<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsAcknowledgement extends Model
{
    protected $fillable = [
        'news_id',
        'user_id',
        'company_id',
        'acknowledged_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<News, NewsAcknowledgement>
     */
    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    /**
     * @return BelongsTo<User, NewsAcknowledgement>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
