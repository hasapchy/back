<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $fillable = [
        'company_id',
        'type',
        'title',
        'created_by',
        'last_message_at',
        'is_archived',
        'avatar',
        'pinned_message_id',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'is_archived' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants', 'chat_id', 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function pinnedMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'pinned_message_id');
    }
}
