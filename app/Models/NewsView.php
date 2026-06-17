<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsView extends Model
{
    protected $fillable = [
        'news_id',
        'user_id',
        'company_id',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<News, NewsView>
     */
    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    /**
     * @return BelongsTo<User, NewsView>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
