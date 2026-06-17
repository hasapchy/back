<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsReaction extends Model
{
    use HasFactory;

    protected $fillable = ['news_id', 'user_id', 'emoji'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'news_id' => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * @return BelongsTo<News, NewsReaction>
     */
    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    /**
     * @return BelongsTo<User, NewsReaction>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
