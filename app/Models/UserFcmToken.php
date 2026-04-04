<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserFcmToken extends Model
{
    use HasFactory;

    protected $table = 'user_fcm_token';

    protected $fillable = [
        'user_id',
        'web_token',
        'mobile_token',
    ];

    /**
     * Связь с пользователем.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
