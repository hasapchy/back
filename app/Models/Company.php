<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    /**
     * Пользователи, принадлежащие к компании
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user');
    }
}
