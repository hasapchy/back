<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'show_deleted_transactions',
        'rounding_decimals',
        'rounding_enabled',
    ];

    protected $attributes = [
        'logo' => 'logo.jpg',
        'show_deleted_transactions' => false,
        'rounding_decimals' => 2,
        'rounding_enabled' => true,
    ];

    protected $casts = [
        'show_deleted_transactions' => 'boolean',
        'rounding_enabled' => 'boolean',
    ];

    /**
     * Пользователи, принадлежащие к компании
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user');
    }
}
