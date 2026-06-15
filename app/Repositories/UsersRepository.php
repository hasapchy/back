<?php

namespace App\Repositories;

use App\Models\User;
use App\Services\CacheService;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\CashRegisterUser;
use App\Models\Transaction;
use App\Models\Order;
use App\Models\Sale;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;
use App\Models\WhMovement;
use App\Models\Project;
use App\Models\OrderProduct;
use App\Models\Client;
use App\Models\Category;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\CashTransfer;
use App\Models\TransactionCategory;
use App\Models\OrderStatusCategory;
use App\Models\ProjectStatus;
use App\Models\Template;
use App\Models\Comment;
use App\Models\EmployeeSalary;
use App\Models\Currency;
use App\Models\ClientsPhone;
use App\Models\ClientsEmail;
use App\Services\ClientBalanceService;
use App\Support\ClientBalanceViewAccess;
use App\Services\Timeline\TimelineCache;

class UsersRepository extends BaseRepository
{
    /**
     * Получить пользователей с пагинацией
     *
     * @param int $page Номер страницы
     * @param int $perPage Количество записей на страницу
     * @param string|null $search Поисковый запрос
     * @param bool|null $statusFilter Фильтр по статусу (is_active)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($page = 1, $perPage = 20, $search = null, $statusFilter = null)
    {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('users_paginated', [$perPage, $search, $statusFilter, $currentUser?->id, $companyId]);

        $ttl = (!$search && !$statusFilter) ? 1800 : 600;

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $statusFilter, $page, $currentUser) {
            $companyId = $this->getCurrentCompanyId();

            $query = $this->buildUsersListQuery($search, $statusFilter);
            $query->with([
                'creator:id,name,surname,photo',
                'companies:id,name',
                'departments' => function ($q) use ($companyId) {
                    if ($companyId) {
                        $q->where('company_id', $companyId);
                    }
                    $q->select('departments.id', 'departments.title', 'departments.company_id');
                },
            ]);

            $paginated = $query->orderBy('users.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int) $page);

            $users = new \Illuminate\Database\Eloquent\Collection($paginated->getCollection()->all());
            if ($users->isEmpty()) {
                return $paginated;
            }

            $userIds = $users->pluck('id');
            $salariesMap = $this->getSalariesMap($userIds, $companyId);
            [$permissionsMap, $rolesMap] = $this->getPermissionsAndRolesMaps($users, $userIds, $companyId);
            $companyRolesMap = $this->getCompanyRolesMap($userIds);
            $allPermissionsForAdmins = !$companyId
                ? \Spatie\Permission\Models\Permission::where('guard_name', 'api')->get()
                : null;

            $paginated->getCollection()->transform(function ($user) use ($salariesMap, $permissionsMap, $rolesMap, $companyRolesMap, $allPermissionsForAdmins) {
                $user->last_salary = $salariesMap[$user->id] ?? null;
                $user->setRelation('permissions', $user->is_admin && $allPermissionsForAdmins
                    ? $allPermissionsForAdmins
                    : ($permissionsMap[$user->id] ?? collect()));
                $user->setRelation('roles', $rolesMap[$user->id] ?? collect());
                $user->company_roles = $companyRolesMap[$user->id] ?? [];
                return $user;
            });

            return $paginated;
        }, (int) $page);
    }

    /**
     * Базовый запрос списка пользователей с фильтрами.
     *
     * @param  string|null  $search
     * @param  bool|null  $statusFilter  Фильтр по is_active (true/false) или null — без фильтра
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildUsersListQuery($search = null, $statusFilter = null)
    {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();

        $query = User::select([
            'users.id',
            'users.creator_id',
            'users.name',
            'users.surname',
            'users.email',
            'users.phone',
            'users.is_active',
            'users.hire_date',
            'users.dismissal_date',
            'users.birthday',
            'users.position',
            'users.is_admin',
            'users.is_simple_user',
            'users.simple_category_id',
            'users.simple_warehouse_id',
            'users.photo',
            'users.created_at',
            'users.updated_at',
            'users.last_login_at',
        ]);

        if ($companyId) {
            $query->join('company_user', 'users.id', '=', 'company_user.user_id')
                ->where('company_user.company_id', $companyId)
                ->distinct();
        }

        if (! optional($currentUser)->is_admin) {
            $permissions = $this->getUserPermissionsForCompany($currentUser);
            $hasViewAll = in_array('users_view_all', $permissions) || in_array('users_view', $permissions);
            if (! $hasViewAll && in_array('users_view_own', $permissions)) {
                $query->where('users.id', $currentUser->id);
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.surname', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.phone', 'like', "%{$search}%")
                    ->orWhere('users.position', 'like', "%{$search}%");
            });
        }

        if ($statusFilter !== null) {
            $query->where('users.is_active', $statusFilter);
        }

        return $query;
    }

    /**
     * Подсчитать количество пользователей по статусам с учётом текущих фильтров (без statusFilter).
     *
     * Возвращает массив с ключами:
     *  - total: общее количество (по фильтру search, без статуса)
     *  - by_status: список [['status' => 'active', 'count' => N], ...]
     *  - admins: количество администраторов
     *
     * @param  string|null  $search
     * @return array
     */
    public function getStatusCountsForFilters($search = null): array
    {
        $baseQuery = $this->buildUsersListQuery($search, null);

        $total = (clone $baseQuery)->distinct()->count('users.id');
        $admins = (clone $baseQuery)->where('users.is_admin', true)->distinct()->count('users.id');
        $active = (clone $baseQuery)->where('users.is_active', true)->distinct()->count('users.id');
        $inactive = (clone $baseQuery)->where('users.is_active', false)->distinct()->count('users.id');

        return [
            'total' => (int) $total,
            'by_status' => [
                ['status' => 'active', 'count' => (int) $active],
                ['status' => 'inactive', 'count' => (int) $inactive],
            ],
            'admins' => (int) $admins,
        ];
    }

    /**
     * Получить всех пользователей
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems()
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('users_all', [$currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function () use ($currentUser, $companyId) {
            $users = $this->buildUsersQuery($currentUser, $companyId)->get();

            if ($users->isEmpty()) {
                return collect();
            }

            $userIds = $users->pluck('id');
            $salariesMap = $this->getSalariesMap($userIds, $companyId);
            [$permissionsMap, $rolesMap] = $this->getPermissionsAndRolesMaps($users, $userIds, $companyId);
            $companyRolesMap = $this->getCompanyRolesMap($userIds);
            $allPermissionsForAdmins = !$companyId
                ? \Spatie\Permission\Models\Permission::where('guard_name', 'api')->get()
                : null;

            return $users->map(function ($user) use ($salariesMap, $permissionsMap, $rolesMap, $companyRolesMap, $allPermissionsForAdmins) {
                $user->last_salary = $salariesMap[$user->id] ?? null;
                $user->setRelation('permissions', $user->is_admin && $allPermissionsForAdmins
                    ? $allPermissionsForAdmins
                    : ($permissionsMap[$user->id] ?? collect()));
                $user->setRelation('roles', $rolesMap[$user->id] ?? collect());
                $user->company_roles = $companyRolesMap[$user->id] ?? [];
                return $user;
            });
        });
    }

    /**
     * Построить базовый запрос для получения пользователей
     *
     * @param \App\Models\User|null $currentUser Текущий пользователь
     * @param int|null $companyId ID компании
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildUsersQuery($currentUser, $companyId)
    {
        $query = User::select([
            'users.id',
            'users.creator_id',
            'users.name',
            'users.surname',
            'users.email',
            'users.phone',
            'users.is_active',
            'users.hire_date',
            'users.dismissal_date',
            'users.birthday',
            'users.position',
            'users.is_admin',
            'users.is_simple_user',
            'users.simple_category_id',
            'users.simple_warehouse_id',
            'users.photo',
            'users.created_at',
            'users.last_login_at'
        ])->with([
            'creator:id,name,surname,photo',
            'companies:id,name',
            'departments' => function ($q) use ($companyId) {
                if ($companyId) {
                    $q->where('company_id', $companyId);
                }
                $q->select('departments.id', 'departments.title', 'departments.company_id');
            },
        ]);

        if ($companyId) {
            $query->join('company_user', 'users.id', '=', 'company_user.user_id')
                ->where('company_user.company_id', $companyId)
                ->distinct();
        }

        if (!optional($currentUser)->is_admin) {
            $permissions = $this->getUserPermissionsForCompany($currentUser);
            $hasViewAll = in_array('users_view_all', $permissions) || in_array('users_view', $permissions);
            if (!$hasViewAll && in_array('users_view_own', $permissions)) {
                $query->where('users.id', $currentUser->id);
            }
        }

        return $query->orderBy('users.created_at', 'desc');
    }

    /**
     * Получить карту зарплат пользователей
     *
     * @param \Illuminate\Support\Collection $userIds Коллекция ID пользователей
     * @param int|null $companyId ID компании
     * @return array Массив зарплат, сгруппированных по ID пользователя
     */
    protected function getSalariesMap($userIds, $companyId): array
    {
        if ($userIds->isEmpty() || !$companyId) {
            return [];
        }

        return EmployeeSalary::whereIn('user_id', $userIds)
            ->where('company_id', $companyId)
            ->with('currency:id,code,name')
            ->orderBy('user_id')
            ->orderBy('start_date', 'desc')
            ->get()
            ->groupBy('user_id')
            ->map(fn($salaries) => [
                'id' => $salaries->first()->id,
                'amount' => $salaries->first()->amount,
                'start_date' => $salaries->first()->start_date,
                'end_date' => $salaries->first()->end_date,
                'currency' => $salaries->first()->currency,
            ])
            ->toArray();
    }

    /**
     * Получить карты разрешений и ролей для пользователей
     *
     * @param \Illuminate\Database\Eloquent\Collection $users Коллекция пользователей
     * @param \Illuminate\Support\Collection $userIds Коллекция ID пользователей
     * @param int|null $companyId ID компании
     * @return array Массив [permissionsMap, rolesMap]
     */
    protected function getPermissionsAndRolesMaps($users, $userIds, $companyId): array
    {
        $permissionsMap = [];
        $rolesMap = [];

        if ($userIds->isEmpty()) {
            return [$permissionsMap, $rolesMap];
        }

        if ($companyId) {
            [$permissionsMap, $rolesMap] = $this->getCompanyScopedPermissionsAndRoles($users, $userIds, $companyId);
        } else {
            $allPermissions = \Spatie\Permission\Models\Permission::where('guard_name', 'api')->get();
            $users->load(['roles.permissions', 'permissions']);
            foreach ($users as $user) {
                $permissionsMap[$user->id] = $user->is_admin
                    ? $allPermissions
                    : $user->getPermissionsViaRoles()->merge($user->getDirectPermissions())->unique('id');
                $rolesMap[$user->id] = $user->roles;
            }
        }

        return [$permissionsMap, $rolesMap];
    }

    /**
     * Получить карты разрешений и ролей для пользователей в рамках компании
     *
     * @param \Illuminate\Database\Eloquent\Collection $users Коллекция пользователей
     * @param \Illuminate\Support\Collection $userIds Коллекция ID пользователей
     * @param int $companyId ID компании
     * @return array Массив [permissionsMap, rolesMap]
     */
    protected function getCompanyScopedPermissionsAndRoles($users, $userIds, $companyId): array
    {
        $permissionsMap = [];
        $rolesMap = [];
        $allPermissions = \Spatie\Permission\Models\Permission::where('guard_name', 'api')->get();

        $companyUserRoles = DB::table('company_user_role')
            ->whereIn('creator_id', $userIds)
            ->where('company_id', $companyId)
            ->get()
            ->groupBy('creator_id');

        $allRoleIds = $companyUserRoles->flatten()->pluck('role_id')->unique()->filter();

        if ($allRoleIds->isEmpty()) {
            foreach ($users as $user) {
                if ($user->is_admin) {
                    $permissionsMap[$user->id] = $allPermissions;
                }
            }
            return [$permissionsMap, $rolesMap];
        }

        $roles = \Spatie\Permission\Models\Role::where('guard_name', 'api')
            ->whereIn('id', $allRoleIds)
            ->with('permissions:id,name')
            ->get()
            ->keyBy('id');

        $allPermissionIds = $roles->flatMap->permissions->pluck('id')->unique();
        $permissions = $allPermissionIds->isNotEmpty()
            ? \Spatie\Permission\Models\Permission::where('guard_name', 'api')
            ->whereIn('id', $allPermissionIds)
            ->get()
            ->keyBy('id')
            : collect();

        foreach ($companyUserRoles as $userId => $userRoles) {
            $roleIds = $userRoles->pluck('role_id');
            $userRolesCollection = $roles->whereIn('id', $roleIds)->values();
            $rolesMap[$userId] = $userRolesCollection;
            $permissionsMap[$userId] = $permissions->whereIn(
                'id',
                $userRolesCollection->flatMap->permissions->pluck('id')->unique()
            )->values();
        }

        foreach ($users as $user) {
            if ($user->is_admin) {
                $permissionsMap[$user->id] = $allPermissions;
            }
        }

        return [$permissionsMap, $rolesMap];
    }

    /**
     * Получить карту ролей компаний для пользователей
     *
     * @param \Illuminate\Support\Collection $userIds Коллекция ID пользователей
     * @return array Массив ролей, сгруппированных по ID пользователя
     */
    protected function getCompanyRolesMap($userIds): array
    {
        if ($userIds->isEmpty()) {
            return [];
        }

        $allCompanyRoles = DB::table('company_user_role')
            ->whereIn('creator_id', $userIds)
            ->select('creator_id', 'company_id', 'role_id')
            ->get()
            ->groupBy('creator_id');

        $allRoleIds = $allCompanyRoles->flatten()->pluck('role_id')->unique()->filter();

        if ($allRoleIds->isEmpty()) {
            return [];
        }

        $allRoles = \Spatie\Permission\Models\Role::where('guard_name', 'api')
            ->whereIn('id', $allRoleIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        $companyRolesMap = [];
        foreach ($allCompanyRoles as $userId => $userCompanyRoles) {
            $companyRolesMap[$userId] = $userCompanyRoles
                ->groupBy('company_id')
                ->map(fn($roles, $compId) => [
                    'company_id' => $compId,
                    'role_ids' => $roles->pluck('role_id')
                        ->map(fn($roleId) => $allRoles->get($roleId)?->name)
                        ->filter()
                        ->values()
                        ->toArray()
                ])
                ->values()
                ->toArray();
        }

        return $companyRolesMap;
    }

    /**
     * Создать пользователя
     *
     * @param array $data Данные пользователя
     * @return User
     * @throws \Exception
     */
    public function createItem(array $data)
    {
        return DB::transaction(function () use ($data) {
            if (empty($data['companies']) || !is_array($data['companies'])) {
                throw new \InvalidArgumentException(__('api.users.must_have_company'));
            }

            $user = new User();
            $user->name     = $data['name'];
            $user->surname  = $data['surname'] ?? null;
            $user->email    = $data['email'];
            $user->phone    = !empty($data['phone']) ? $data['phone'] : null;
            $user->password = $data['password'];
            $user->hire_date = $data['hire_date'] ?? null;
            $user->dismissal_date = $data['dismissal_date'] ?? null;
            $user->birthday = !empty($data['birthday']) ? Carbon::parse($data['birthday']) : null;
            $user->is_active = $data['is_active'] ?? true;
            $user->position = $data['position'] ?? null;
            $user->is_admin = $data['is_admin'] ?? false;
            $user->is_simple_user = $data['is_simple_user'] ?? false;
            if (! empty($data['simple_category_id'])) {
                $user->simple_category_id = (int) $data['simple_category_id'];
            }
            if (! empty($data['simple_warehouse_id'])) {
                $user->simple_warehouse_id = (int) $data['simple_warehouse_id'];
            }

            if (isset($data['photo'])) {
                $user->photo = $data['photo'];
            }

            $user->creator_id = auth('api')->id();

            Log::info('users.repository.createItem simple fields before save', [
                'data_has_is_simple_user' => array_key_exists('is_simple_user', $data),
                'data_is_simple_user' => $data['is_simple_user'] ?? null,
                'data_has_simple_category_id' => array_key_exists('simple_category_id', $data),
                'data_simple_category_id' => $data['simple_category_id'] ?? null,
                'model_is_simple_user' => $user->is_simple_user,
                'model_simple_category_id' => $user->simple_category_id,
            ]);

            $user->save();

            Log::info('users.repository.createItem simple fields after save', [
                'user_id' => $user->id,
                'model_is_simple_user' => $user->is_simple_user,
                'model_simple_category_id' => $user->simple_category_id,
            ]);

            if (isset($data['companies'])) {
                $user->companies()->sync($data['companies']);
            }

            $this->syncEmployeeClientsFromUser($user);

            $this->syncUserRoles($user, $data);

            if (array_key_exists('departments', $data)) {
                $user->departments()->sync($data['departments'] ?? []);
            }

            if ($user->companies()->count() === 0) {
                throw new \InvalidArgumentException(__('api.users.must_have_company'));
            }

            $this->loadUserRelations($user);

            CacheService::invalidateByLike('%users_paginated%');
            CacheService::invalidateByLike('%users_all%');
            CacheService::invalidateDepartmentsCache();
            CacheService::invalidateClientsCache();

            return $user;
        });
    }

    /**
     * Обновить пользователя
     *
     * @param int $id ID пользователя
     * @param array $data Данные для обновления
     * @return User
     * @throws \Exception
     */
    public function updateItem($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $user = User::findOrFail($id);

            $user->name = $data['name'] ?? $user->name;
            $user->surname = array_key_exists('surname', $data) ? $data['surname'] : $user->surname;
            $user->email = $data['email'] ?? $user->email;
            $user->phone = array_key_exists('phone', $data) ? ($data['phone'] ?: null) : $user->phone;
            $user->hire_date = array_key_exists('hire_date', $data) ? $data['hire_date'] : $user->hire_date;
            $user->dismissal_date = array_key_exists('dismissal_date', $data) ? $data['dismissal_date'] : $user->dismissal_date;
            $user->birthday = array_key_exists('birthday', $data) && $data['birthday']
                ? Carbon::parse($data['birthday'])->format('Y-m-d')
                : (array_key_exists('birthday', $data) ? null : $user->birthday);
            $user->is_active = $data['is_active'] ?? $user->is_active;
            $user->position = array_key_exists('position', $data) ? $data['position'] : $user->position;
            $user->is_admin = $data['is_admin'] ?? $user->is_admin;
            if (array_key_exists('is_simple_user', $data)) {
                $user->is_simple_user = $data['is_simple_user'];
            }
            if (array_key_exists('simple_category_id', $data)) {
                $user->simple_category_id = $data['simple_category_id'] ? (int) $data['simple_category_id'] : null;
            }
            if (array_key_exists('simple_warehouse_id', $data)) {
                $user->simple_warehouse_id = $data['simple_warehouse_id'] ? (int) $data['simple_warehouse_id'] : null;
            }

            if (isset($data['photo'])) {
                $user->photo = $data['photo'];
            }

            if (!empty($data['password'])) {
                $user->password = $data['password'];
            }

            Log::info('users.repository.updateItem simple fields before save', [
                'target_user_id' => (int) $id,
                'data_has_is_simple_user' => array_key_exists('is_simple_user', $data),
                'data_is_simple_user' => $data['is_simple_user'] ?? null,
                'data_has_simple_category_id' => array_key_exists('simple_category_id', $data),
                'data_simple_category_id' => $data['simple_category_id'] ?? null,
                'model_is_simple_user' => $user->is_simple_user,
                'model_simple_category_id' => $user->simple_category_id,
            ]);

            $passwordChanged = ! empty($data['password']);
            $user->save();

            if ($passwordChanged) {
                app(\App\Services\UserCredentialRevocationService::class)->revokeAll(
                    $user,
                    request(),
                    'password_changed'
                );
            }

            $user->refresh();

            Log::info('users.repository.updateItem simple fields after save', [
                'target_user_id' => (int) $id,
                'model_is_simple_user' => $user->is_simple_user,
                'model_simple_category_id' => $user->simple_category_id,
            ]);

            if (isset($data['companies'])) {
                $user->companies()->sync($data['companies']);
            }

            $this->syncEmployeeClientsFromUser($user);

            $this->syncUserRoles($user, $data);

            if (array_key_exists('departments', $data)) {
                $user->departments()->sync($data['departments'] ?? []);
            }

            if ($user->companies()->count() === 0) {
                throw new \InvalidArgumentException(__('api.users.must_have_company'));
            }

            $this->loadUserRelations($user);

            CacheService::invalidateByLike('%users_paginated%');
            CacheService::invalidateByLike('%users_all%');
            CacheService::invalidateDepartmentsCache();
            CacheService::invalidateClientsCache();

            return $user;
        });
    }

    /**
     * Синхронизировать роли пользователя
     *
     * @param User $user Пользователь
     * @param array $data Данные с ролями
     * @return void
     */
    protected function syncUserRoles(User $user, array $data): void
    {
        if (isset($data['company_roles']) && is_array($data['company_roles'])) {
            $user->companyRoles()->detach();

            $selectedCompanyIds = isset($data['companies']) && is_array($data['companies']) ? $data['companies'] : [];

            $roleNamesByCompany = [];
            foreach ($data['company_roles'] as $companyRole) {
                if (isset($companyRole['company_id']) && isset($companyRole['role_ids']) && is_array($companyRole['role_ids'])) {
                    if (empty($selectedCompanyIds) || in_array($companyRole['company_id'], $selectedCompanyIds)) {
                        foreach ($companyRole['role_ids'] as $roleName) {
                            if (!isset($roleNamesByCompany[$companyRole['company_id']])) {
                                $roleNamesByCompany[$companyRole['company_id']] = [];
                            }
                            $roleNamesByCompany[$companyRole['company_id']][] = $roleName;
                        }
                    }
                }
            }

            if (!empty($roleNamesByCompany)) {
                $allRoleNames = collect($roleNamesByCompany)->flatten()->unique()->values()->all();
                $roles = Role::where('guard_name', 'api')
                    ->whereIn('name', $allRoleNames)
                    ->get()
                    ->keyBy(function ($role) {
                        return $role->company_id . '|' . $role->name;
                    });

                foreach ($roleNamesByCompany as $companyId => $roleNames) {
                    foreach ($roleNames as $roleName) {
                        $key = $companyId . '|' . $roleName;
                        $role = $roles->get($key);
                        if ($role) {
                            $user->companyRoles()->attach($role->id, ['company_id' => $companyId]);
                        }
                    }
                }
            }
        } elseif (isset($data['roles'])) {
            $user->syncRoles($data['roles']);
        }
    }

    /**
     * Загрузить связи пользователя
     *
     * @param User $user Пользователь
     * @return void
     */
    protected function loadUserRelations(User $user): void
    {
        $companyId = $this->getCurrentCompanyId();
        $user->setRelation('permissions', $companyId ? $user->getAllPermissionsForCompany((int)$companyId) : $user->getAllPermissions());
        if ($companyId) {
            $user->setRelation('roles', $user->getRolesForCompany((int)$companyId));
        } else {
            $user->load(['roles']);
        }
        $user->load(['creator:id,name,surname,photo', 'companies', 'departments:id,title,company_id']);
        $user->company_roles = $user->getAllCompanyRoles();
    }

    /**
     * Трансформировать пользователя со связями
     *
     * @param User $user Пользователь
     * @param int|null $companyId ID компании
     * @return User
     */
    protected function transformUserWithRelations(User $user, ?int $companyId): User
    {
        $user->setRelation('permissions', $companyId ? $user->getAllPermissionsForCompany((int)$companyId) : $user->getAllPermissions());
        if ($companyId) {
            $user->setRelation('roles', $user->getRolesForCompany((int)$companyId));
        }
        $user->company_roles = $user->getAllCompanyRoles();

        return $user;
    }

    /**
     * Удалить пользователя
     *
     * @param int $id ID пользователя
     * @return bool
     * @throws \Exception
     */
    public function deleteItem($id)
    {
        $user = User::findOrFail($id);

        if ($user->id === 1) {
            throw new \Exception(__('api.users.cannot_delete_root_admin'));
        }

        $relatedData = $this->checkUserRelatedData($user);

        if (!empty($relatedData)) {
            $message = 'Нельзя удалить пользователя, так как он связан с данными: ' . implode(', ', $relatedData) . '. ';
            $message .= 'Вместо удаления рекомендуется отключить пользователя (установить is_active = false).';
            throw new \Exception($message);
        }

        return DB::transaction(function () use ($user) {
            $user->companies()->detach();
            $user->warehouses()->detach();
            $user->projects()->detach();
            $user->categories()->detach();
            $user->roles()->detach();

            $user->companyRoles()->detach();
            CashRegisterUser::where('user_id', $user->id)->delete();

            $user->delete();

            CacheService::invalidateByLike('%users_paginated%');
            CacheService::invalidateByLike('%users_all%');

            return true;
        });
    }

    /**
     * Проверить связанные данные пользователя
     *
     * @param User $user Пользователь
     * @return array Массив строк с описанием связанных данных
     */
    protected function checkUserRelatedData(User $user): array
    {
        $relatedData = [];

        $checks = [
            [Transaction::class, 'creator_id', 'транзакции'],
            [Order::class, 'creator_id', 'заказы'],
            [Sale::class, 'creator_id', 'продажи'],
            [WhReceipt::class, 'creator_id', 'приходы на склад'],
            [WhWriteoff::class, 'creator_id', 'списания со склада'],
            [WhMovement::class, 'creator_id', 'перемещения между складами'],
            [Project::class, 'creator_id', 'проекты'],
            [Client::class, 'employee_id', 'клиенты как сотрудник'],
            [Client::class, 'creator_id', 'клиенты'],
            [Category::class, 'creator_id', 'категории'],
            [Product::class, 'creator_id', 'товары'],
            [Invoice::class, 'creator_id', 'счета'],
            [CashTransfer::class, 'creator_id', 'переводы между кассами'],
            [TransactionCategory::class, 'creator_id', 'категории транзакций'],
            [OrderStatusCategory::class, 'creator_id', 'категории статусов заказов'],
            [ProjectStatus::class, 'creator_id', 'статусы проектов'],
            [Template::class, 'creator_id', 'шаблоны'],
            [Comment::class, 'creator_id', 'комментарии'],
        ];

        foreach ($checks as [$model, $field, $label]) {
            $count = $model::where($field, $user->id)->count();
            if ($count > 0) {
                $relatedData[] = "{$label} ({$count})";
            }
        }

        return $relatedData;
    }

    /**
     * Получить зарплаты сотрудника
     *
     * @param int $userId ID пользователя
     * @param int|null $companyId ID компании (если null, используется текущая компания)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSalaries($userId, ?int $companyId = null)
    {
        $companyId = $companyId ?? $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('user_salaries', [$userId, $companyId]);

        $salaries = CacheService::remember($cacheKey, function () use ($userId, $companyId) {
            $query = EmployeeSalary::where('user_id', $userId)
                ->with(['currency:id,code,name']);

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            return $query->orderBy('start_date', 'desc')->get();
        }, $this->getCacheTTL('user_data'));

        $allowedTypes = ClientBalanceViewAccess::getAllowedBalanceTypes(auth('api')->user(), $companyId);
        if ($allowedTypes === []) {
            return $salaries;
        }

        return $salaries->filter(
            fn (EmployeeSalary $salary) => in_array((int) $salary->payment_type, $allowedTypes, true)
        )->values();
    }

    /**
     * Создать зарплату сотрудника
     *
     * @param int $userId ID пользователя
     * @param array $data Данные зарплаты
     * @param bool $isClose Закрыть пересекающуюся зарплату (дата окончания = дата начала новой − 1 день)
     * @return EmployeeSalary
     */
    public function createSalary($userId, array $data, bool $isClose = false)
    {
        $companyId = $this->getCurrentCompanyId();

        return DB::transaction(function () use ($userId, $data, $companyId, $isClose) {
            $startDate = $data['start_date'];
            $endDate = $data['end_date'] ?? null;
            $paymentType = $data['payment_type'] ?? false;
            $allowedTypes = ClientBalanceViewAccess::getAllowedBalanceTypes(auth('api')->user(), $companyId);
            if ($allowedTypes !== [] && ! in_array($paymentType ? 1 : 0, $allowedTypes, true)) {
                throw new \DomainException('salary_payment_type_forbidden');
            }

            if ($endDate === null) {
                $activeSalary = EmployeeSalary::where('user_id', $userId)
                    ->where('company_id', $companyId)
                    ->whereNull('end_date')
                    ->where('payment_type', $paymentType)
                    ->first();

                if ($activeSalary) {
                    if ($isClose) {
                        $activeSalary->update([
                            'end_date' => Carbon::parse($startDate)->subDay()->format('Y-m-d'),
                        ]);
                    } else {
                        throw new \DomainException('salary_overlap');
                    }
                }
            }

            $conflictingSalary = EmployeeSalary::where('user_id', $userId)
                ->where('company_id', $companyId)
                ->where('payment_type', $paymentType)
                ->where(function ($query) use ($startDate, $endDate) {
                    $query->where(function ($q) use ($startDate, $endDate) {
                        $q->where(function ($subQ) use ($startDate, $endDate) {
                            $subQ->where('start_date', '<=', $endDate ?? '9999-12-31')
                                ->where(function ($dateQ) use ($startDate) {
                                    $dateQ->whereNull('end_date')
                                        ->orWhere('end_date', '>=', $startDate);
                                });
                        });
                    });
                })
                ->first();

            if ($conflictingSalary) {
                if ($isClose) {
                    $conflictingSalary->update([
                        'end_date' => Carbon::parse($startDate)->subDay()->format('Y-m-d'),
                    ]);
                } else {
                    throw new \DomainException('salary_overlap');
                }
            }

            $salary = EmployeeSalary::create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'amount' => $data['amount'],
                'currency_id' => $data['currency_id'],
                'payment_type' => $paymentType,
                'note' => $data['note'] ?? null,
            ]);

            CacheService::invalidateByLike('%user_salaries%');

            return $salary->load('currency');
        });
    }

    /**
     * Обновить зарплату сотрудника
     *
     * @param int $salaryId ID зарплаты
     * @param array $data Данные для обновления
     * @return EmployeeSalary
     */
    public function updateSalary($salaryId, array $data)
    {
        return DB::transaction(function () use ($salaryId, $data) {
            $salary = EmployeeSalary::findOrFail($salaryId);
            if ($salary->end_date !== null) {
                throw new \DomainException('salary_inactive_locked');
            }

            $newStartDate = $data['start_date'] ?? $salary->start_date;
            $newEndDate = array_key_exists('end_date', $data) ? $data['end_date'] : $salary->end_date;
            $newPaymentType = array_key_exists('payment_type', $data) ? $data['payment_type'] : $salary->payment_type;
            $allowedTypes = ClientBalanceViewAccess::getAllowedBalanceTypes(auth('api')->user(), $salary->company_id);
            if ($allowedTypes !== [] && ! in_array($newPaymentType ? 1 : 0, $allowedTypes, true)) {
                throw new \DomainException('salary_payment_type_forbidden');
            }

            if (array_key_exists('end_date', $data) && $newEndDate === null) {
                $activeSalary = EmployeeSalary::where('user_id', $salary->user_id)
                    ->where('company_id', $salary->company_id)
                    ->whereNull('end_date')
                    ->where('payment_type', $newPaymentType)
                    ->where('id', '!=', $salaryId)
                    ->first();

                if ($activeSalary) {
                    throw new \DomainException('salary_overlap');
                }
            }

            if (isset($data['start_date']) || array_key_exists('end_date', $data) || array_key_exists('payment_type', $data)) {
                $conflictingSalary = EmployeeSalary::where('user_id', $salary->user_id)
                    ->where('company_id', $salary->company_id)
                    ->where('payment_type', $newPaymentType)
                    ->where('id', '!=', $salaryId)
                    ->where(function ($query) use ($newStartDate, $newEndDate) {
                        $query->where(function ($q) use ($newStartDate, $newEndDate) {
                            $q->where('start_date', '<=', $newEndDate ?? '9999-12-31')
                                ->where(function ($dateQ) use ($newStartDate) {
                                    $dateQ->whereNull('end_date')
                                        ->orWhere('end_date', '>=', $newStartDate);
                                });
                        });
                    })
                    ->first();

                if ($conflictingSalary) {
                    throw new \DomainException('salary_overlap');
                }
            }

            $updateData = [];

            if (isset($data['start_date'])) {
                $updateData['start_date'] = $data['start_date'];
            }
            if (array_key_exists('end_date', $data)) {
                $updateData['end_date'] = $data['end_date'];
            }
            if (isset($data['amount'])) {
                $updateData['amount'] = $data['amount'];
            }
            if (isset($data['currency_id'])) {
                $updateData['currency_id'] = $data['currency_id'];
            }
            if (array_key_exists('payment_type', $data)) {
                $updateData['payment_type'] = $data['payment_type'];
            }
            if (array_key_exists('note', $data)) {
                $updateData['note'] = $data['note'];
            }

            $salary->update($updateData);

            CacheService::invalidateByLike('%user_salaries%');

            return $salary->fresh('currency');
        });
    }

    /**
     * Удалить зарплату сотрудника
     *
     * @param int $salaryId ID зарплаты
     * @return bool
     */
    public function deleteSalary($salaryId)
    {
        $salary = EmployeeSalary::findOrFail($salaryId);
        if ($salary->end_date !== null) {
            throw new \DomainException('salary_inactive_locked');
        }
        $deleted = $salary->delete();

        if ($deleted) {
            CacheService::invalidateByLike('%user_salaries%');
        }

        return $deleted;
    }

    /**
     * Получить баланс сотрудника через клиента
     *
     * @param int $userId ID пользователя
     * @param int|null $companyId ID компании (если null, используется текущая компания)
     * @return array|null Массив с client_id и balance, или null если клиент не найден
     */
    public function getEmployeeBalance($userId, ?int $companyId = null)
    {
        $companyId = $companyId ?? $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('user_employee_balance', [$userId, $companyId]);

        return CacheService::remember($cacheKey, function () use ($userId, $companyId) {
            $query = Client::where('employee_id', $userId)
                ->where('client_type', 'employee')
                ->select('id', 'company_id');

            $query = $this->addCompanyFilterDirect($query, 'clients');
            $client = $query->with('defaultBalance:id,client_id,balance')->first();

            if (!$client) {
                return null;
            }

            return [
                'client_id' => $client->id,
                'balance' => ClientBalanceViewAccess::visibleDefaultBalanceValue(
                    $client,
                    auth('api')->user(),
                    $companyId
                ),
            ];
        }, 900);
    }

    /**
     * Получить историю баланса сотрудника
     *
     * @param int $userId ID пользователя
     * @param int|null $companyId ID компании (если null, используется текущая компания)
     * @return array Массив транзакций с описаниями
     */
    public function getEmployeeBalanceHistory($userId, ?int $companyId = null)
    {
        $balanceInfo = $this->getEmployeeBalance($userId, $companyId);

        if (!$balanceInfo || !$balanceInfo['client_id']) {
            return [];
        }

        return app(ClientsRepository::class)->getBalanceHistory($balanceInfo['client_id']);
    }

    public function searchUser(string $search_request)
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('users_search_' . md5($search_request), [$currentUser?->id, $companyId]);

        return CacheService::rememberSearch($cacheKey, function () use ($search_request, $currentUser, $companyId) {
            $searchTerms = explode(' ', $search_request);

            $query = User::select([
                'users.id',
                'users.creator_id',
                'users.name',
                'users.surname',
                'users.email',
                'users.phone',
                'users.is_active',
                'users.hire_date',
                'users.dismissal_date',
                'users.birthday',
                'users.position',
                'users.is_admin',
                'users.is_simple_user',
                'users.simple_category_id',
                'users.simple_warehouse_id',
                'users.photo',
                'users.created_at',
                'users.updated_at',
                'users.last_login_at'
            ])->with([
                'creator:id,name,surname,photo',
                'companies:id,name',
            ]);

            if ($companyId) {
                $query->join('company_user', 'users.id', '=', 'company_user.user_id')
                    ->where('company_user.company_id', $companyId)
                    ->distinct();
            }

            if (!optional($currentUser)->is_admin) {
                $permissions = $this->getUserPermissionsForCompany($currentUser);
                $hasViewAll = in_array('users_view_all', $permissions) || in_array('users_view', $permissions);
                if (!$hasViewAll && in_array('users_view_own', $permissions)) {
                    $query->where('users.id', $currentUser->id);
                }
            }

            $query->where('users.is_active', true);

            $query->where(function ($q) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $q->orWhere(function ($subQuery) use ($term) {
                        $subQuery->where('users.name', 'like', "%{$term}%")
                            ->orWhere('users.surname', 'like', "%{$term}%")
                            ->orWhere('users.email', 'like', "%{$term}%")
                            ->orWhere('users.position', 'like', "%{$term}%");
                    });
                }
            });

            $results = $query->limit(50)->get();

            $userIds = $results->pluck('id');
            if ($userIds->isEmpty()) {
                return collect();
            }

            $salariesMap = $this->getSalariesMap($userIds, $companyId);
            [$permissionsMap, $rolesMap] = $this->getPermissionsAndRolesMaps($results, $userIds, $companyId);
            $companyRolesMap = $this->getCompanyRolesMap($userIds);

            return $results->map(function ($user) use ($salariesMap, $permissionsMap, $rolesMap, $companyRolesMap) {
                $user->last_salary = $salariesMap[$user->id] ?? null;
                $user->setRelation('permissions', $permissionsMap[$user->id] ?? collect());
                $user->setRelation('roles', $rolesMap[$user->id] ?? collect());
                $user->company_roles = $companyRolesMap[$user->id] ?? [];
                return $user;
            });
        });
    }

    /**
     * @param User $user
     * @return void
     */
    private function syncEmployeeClientsFromUser(User $user): void
    {
        $companyIds = $user->companies()->pluck('companies.id')->map(static fn ($id) => (int) $id)->all();

        if ($companyIds === []) {
            return;
        }

        foreach ($companyIds as $companyId) {
            $client = Client::query()
                ->where('employee_id', $user->id)
                ->where('client_type', 'employee')
                ->where('company_id', $companyId)
                ->first();

            if (! $client) {
                $client = Client::create([
                    'creator_id' => auth('api')->id() ?: $user->id,
                    'company_id' => $companyId,
                    'employee_id' => $user->id,
                    'client_type' => 'employee',
                    'first_name' => $user->name,
                    'last_name' => $user->surname,
                    'patronymic' => null,
                    'position' => $user->position,
                    'status' => (bool) $user->is_active,
                    'is_supplier' => false,
                    'is_conflict' => false,
                    'discount' => 0,
                    'discount_type' => 'fixed',
                ]);
                $this->ensureDefaultClientBalance($client, $companyId);
            } else {
                $client->update([
                    'first_name' => $user->name,
                    'last_name' => $user->surname,
                    'position' => $user->position,
                    'status' => (bool) $user->is_active,
                ]);
            }

            $this->syncEmployeeClientPhone($client->id, $user->phone);
            $this->syncEmployeeClientEmail($client->id, $user->email);
            TimelineCache::forget('client', (int) $client->id);
        }
    }

    /**
     * @param Client $client
     * @param int $companyId
     * @return void
     */
    private function ensureDefaultClientBalance(Client $client, int $companyId): void
    {
        if ($client->balances()->exists()) {
            return;
        }

        $defaultCurrency = Currency::query()
            ->where('is_default', true)
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)
                    ->orWhereNull('company_id');
            })
            ->orderByRaw('CASE WHEN company_id = ? THEN 0 ELSE 1 END', [$companyId])
            ->first();

        if (! $defaultCurrency) {
            return;
        }

        ClientBalanceService::createBalance($client, $defaultCurrency, true);
    }

    /**
     * @param int $clientId
     * @return list<int>
     */
    private function getEmployeeClientIds(int $clientId): array
    {
        $employeeId = Client::query()->where('id', $clientId)->value('employee_id');
        if (! $employeeId) {
            return [$clientId];
        }

        return Client::query()
            ->where('employee_id', $employeeId)
            ->where('client_type', 'employee')
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param int $clientId
     * @param string|null $phone
     * @return void
     */
    private function syncEmployeeClientPhone(int $clientId, ?string $phone): void
    {
        $normalizedPhone = $phone !== null && trim($phone) !== '' ? trim($phone) : null;
        $employeeClientIds = $this->getEmployeeClientIds($clientId);

        if ($normalizedPhone === null) {
            ClientsPhone::query()->whereIn('client_id', $employeeClientIds)->delete();

            return;
        }

        $existing = ClientsPhone::query()
            ->whereIn('client_id', $employeeClientIds)
            ->orderBy('id')
            ->first();

        if ($existing) {
            $existing->update(['phone' => $normalizedPhone]);
            ClientsPhone::query()
                ->whereIn('client_id', $employeeClientIds)
                ->where('id', '!=', $existing->id)
                ->delete();

            return;
        }

        ClientsPhone::query()->create([
            'client_id' => $clientId,
            'phone' => $normalizedPhone,
        ]);
    }

    /**
     * @param int $clientId
     * @param string|null $email
     * @return void
     */
    private function syncEmployeeClientEmail(int $clientId, ?string $email): void
    {
        $normalizedEmail = $email !== null && trim($email) !== '' ? trim($email) : null;
        $employeeClientIds = $this->getEmployeeClientIds($clientId);

        if ($normalizedEmail === null) {
            ClientsEmail::query()->whereIn('client_id', $employeeClientIds)->delete();

            return;
        }

        $existing = ClientsEmail::query()
            ->whereIn('client_id', $employeeClientIds)
            ->orderBy('id')
            ->first();

        if ($existing) {
            $existing->update(['email' => $normalizedEmail]);
            ClientsEmail::query()
                ->whereIn('client_id', $employeeClientIds)
                ->where('id', '!=', $existing->id)
                ->delete();

            return;
        }

        ClientsEmail::query()->create([
            'client_id' => $clientId,
            'email' => $normalizedEmail,
        ]);
    }
}
