<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Support\Facades\DB;
use App\Models\Leave;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

    protected $guard_name = 'api';

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($user) {
            if ($user->isDirty('is_active') && !$user->is_active) {
                $user->tokens()->delete();
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'surname',
        'email',
        'password',
        'is_active',
        'hire_date',
        'birthday',
        'position',
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
        'birthday' => 'date:Y-m-d',
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
     * Зарплаты сотрудника
     */
    public function salaries()
    {
        return $this->hasMany(\App\Models\EmployeeSalary::class, 'user_id');
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
    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    /**
     * Получить все разрешения пользователя (глобальные, через роли)
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAllPermissions()
    {
        if ($this->is_admin) {
            return \Spatie\Permission\Models\Permission::where('guard_name', 'api')->get();
        }

        $permissionsViaRoles = $this->getPermissionsViaRoles();
        $directPermissions = $this->getDirectPermissions();

        return $permissionsViaRoles->merge($directPermissions)->unique('id');
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
        if ($this->is_admin) {
            return \Spatie\Permission\Models\Permission::where('guard_name', 'api')->get();
        }

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

        $roleIds = $companyRoles->pluck('role_id')->unique()->filter();
        $roles = $roleIds->isNotEmpty()
            ? \Spatie\Permission\Models\Role::where('guard_name', 'api')
                ->whereIn('id', $roleIds)
                ->get()
                ->keyBy('id')
            : collect();

        $result = [];
        foreach ($companyRoles as $companyRole) {
            $role = $roles->get($companyRole->role_id);

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

    /**
     * Получить полный список ролей пользователя (глобальные + по компаниям)
     */
    public function getAllRoleNames(): array
    {
        $this->loadMissing('roles');

        $globalRoles = $this->roles->pluck('name');
        $companyRoleNames = collect($this->getAllCompanyRoles())
            ->flatMap(static function ($companyRole) {
                return $companyRole['role_ids'] ?? [];
            });

        return $globalRoles
            ->merge($companyRoleNames)
            ->unique()
            ->values()
            ->toArray();
    }
}
