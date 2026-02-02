<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Реакция пользователя на сообщение (один эмодзи на сообщение). */
class MessageReaction extends Model
{
    protected $fillable = ['message_id', 'user_id', 'emoji'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
