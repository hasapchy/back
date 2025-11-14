<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\DB;

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
        'birthday',
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
        'birthday' => 'date',
    ];


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

    /**
     * Роли пользователя в компаниях (many-to-many через company_user_role)
     */
    public function companyRoles()
    {
        return $this->belongsToMany(\Spatie\Permission\Models\Role::class, 'company_user_role', 'user_id', 'role_id')
            ->withPivot('company_id')
            ->withTimestamps();
    }

    /**
     * Получить все разрешения пользователя с учетом текущей компании
     * Если компания не указана, возвращает глобальные разрешения (для обратной совместимости)
     *
     * @param int|null $companyId ID компании
     * @return \Illuminate\Support\Collection
     */
    public function getAllPermissionsForCompany(?int $companyId = null)
    {
        if (!$companyId) {
            return $this->getAllPermissions();
        }

        $roleIds = DB::table('company_user_role')
            ->where('user_id', $this->id)
            ->where('company_id', $companyId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return collect([]);
        }

        $permissionIds = DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->pluck('permission_id')
            ->unique();

        if ($permissionIds->isEmpty()) {
            return collect([]);
        }

        $permissions = \Spatie\Permission\Models\Permission::where('guard_name', 'api')
            ->whereIn('id', $permissionIds)
            ->get();

        return $permissions;
    }

    /**
     * Получить роли пользователя в конкретной компании
     *
     * @param int $companyId ID компании
     * @return \Illuminate\Support\Collection
     */
    public function getRolesForCompany(int $companyId)
    {
        $roleIds = DB::table('company_user_role')
            ->where('user_id', $this->id)
            ->where('company_id', $companyId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return collect([]);
        }

        return \Spatie\Permission\Models\Role::where('guard_name', 'api')
            ->whereIn('id', $roleIds)
            ->get();
    }

    /**
     * Получить все роли пользователя по компаниям
     * Возвращает массив в формате [{company_id: 1, role_ids: ['admin', 'manager']}, ...]
     *
     * @return array
     */
    public function getAllCompanyRoles(): array
    {
        $companyRoles = DB::table('company_user_role')
            ->where('user_id', $this->id)
            ->select('company_id', 'role_id')
            ->get();

        $result = [];
        foreach ($companyRoles as $companyRole) {
            $role = \Spatie\Permission\Models\Role::where('guard_name', 'api')
                ->find($companyRole->role_id);

            if ($role) {
                $companyId = $companyRole->company_id;
                if (!isset($result[$companyId])) {
                    $result[$companyId] = ['company_id' => $companyId, 'role_ids' => []];
                }
                $result[$companyId]['role_ids'][] = $role->name;
            }
        }

        return array_values($result);
    }
}
