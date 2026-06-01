<?php

namespace App\Models;

use App\Enums\TokenClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserAuthSession extends Model
{
    protected $fillable = [
        'user_id',
        'client_type',
        'device_fingerprint',
        'device_name',
        'ip_address',
        'user_agent',
        'last_activity_at',
        'expires_at',
        'laravel_session_id',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<\App\Models\Sanctum\PersonalAccessToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(Sanctum\PersonalAccessToken::class, 'auth_session_id');
    }

    public function isWeb(): bool
    {
        return $this->client_type === TokenClient::Web->value;
    }

    public function isMobile(): bool
    {
        return $this->client_type === TokenClient::Mobile->value;
    }
}
