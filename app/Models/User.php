<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $guard_name = 'api'; // для работы с ролями и правами ya izmenila chtoby rabotat s api

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'hire_date',
        'position',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Защита от удаления пользователя
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($user) {
            throw new \Exception('Пользователя нельзя удалить.');
        });
    }

    /**
     * Защита от изменения поля is_admin
     */
    public function setIsAdminAttribute($value)
    {
        if ($this->exists && $this->is_admin) {
            throw new \Exception('Нельзя убрать права администратора у пользователя.');
        }

        $this->attributes['is_admin'] = $value;
    }

    // Методы для работы с JWT
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function tableSettings()
    {
        return $this->hasMany(UserTableSettings::class, 'user_id');
    }
}
