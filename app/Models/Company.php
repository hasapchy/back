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
    ];

    protected $attributes = [
        'logo' => 'logo.jpg',
        'show_deleted_transactions' => false,
    ];

    protected $casts = [
        'show_deleted_transactions' => 'boolean',
    ];

    /**
     * Пользователи, принадлежащие к компании
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user');
    }
}
