<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationSetting extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'channels',
    ];

    protected $casts = [
        'channels' => 'array',
    ];

    /**
     * @return BelongsTo<User, UserNotificationSetting>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, UserNotificationSetting>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
