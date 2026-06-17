<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReaction extends Model
{
    use HasFactory;

    protected $fillable = ['comment_id', 'user_id', 'emoji'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'comment_id' => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * @return BelongsTo<Comment, CommentReaction>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * @return BelongsTo<User, CommentReaction>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
