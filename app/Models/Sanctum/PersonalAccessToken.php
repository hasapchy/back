<?php

namespace App\Models\Sanctum;

use App\Enums\TokenClient;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'client_type',
        'token',
        'abilities',
        'expires_at',
        'company_id',
        'device_fingerprint',
        'device_name',
    ];

    protected $attributes = [
        'client_type' => 'web',
    ];

    public function scopeForClient(Builder $query, TokenClient $client): Builder
    {
        return $query->where('client_type', $client->value);
    }

    public function isMobile(): bool
    {
        return $this->client_type === TokenClient::Mobile->value;
    }

    public function isWeb(): bool
    {
        return $this->client_type === TokenClient::Web->value;
    }
}
