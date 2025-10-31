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
        'last_login_at',
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
        'last_login_at' => 'datetime',
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
     * Запрещаем только снятие прав администратора у пользователя с ID 1 (главный админ)
     */
    public function setIsAdminAttribute($value)
    {
        // Проверяем: если это пользователь с ID 1, является администратором
        // и пытаются убрать права администратора - блокируем
        if ($this->exists && $this->id === 1 && $this->is_admin && !$value) {
            throw new \Exception('Нельзя убрать права администратора у главного администратора (ID: 1).');
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

    public function categories()
    {
        return $this->belongsToMany(\App\Models\Category::class, 'category_users', 'user_id', 'category_id');
    }

    /**
     * Клиенты, где пользователь является сотрудником (employee/investor)
     */
    public function clientAccounts()
    {
        return $this->hasMany(\App\Models\Client::class, 'employee_id');
    }
}
