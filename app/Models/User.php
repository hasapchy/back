<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasRoles;

    protected $guard_name = 'api';

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
        'photo',
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
        // Проверяем только если пользователь уже существует И пытаемся убрать права администратора
        if ($this->exists && $this->is_admin && !$value) {
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

    /**
     * Компании, к которым принадлежит пользователь
     */
    public function companies()
    {
        return $this->belongsToMany(Company::class, 'company_user');
    }

    /**
     * Склады, к которым принадлежит пользователь
     */
    public function warehouses()
    {
        return $this->belongsToMany(\App\Models\Warehouse::class, 'wh_users', 'user_id', 'warehouse_id');
    }

    /**
     * Проекты, к которым принадлежит пользователь
     */
    public function projectUsers()
    {
        return $this->hasMany(\App\Models\ProjectUser::class, 'user_id');
    }

    /**
     * Проекты пользователя через связь many-to-many
     */
    public function projects()
    {
        return $this->belongsToMany(\App\Models\Project::class, 'project_users', 'user_id', 'project_id');
    }
}
