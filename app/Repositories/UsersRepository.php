<?php

namespace App\Repositories;

use App\Models\User;
use App\Services\CacheService;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
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
            $query = User::select([
                'users.id',
                'users.name',
                'users.surname',
                'users.email',
                'users.is_active',
                'users.hire_date',
                'users.birthday',
                'users.position',
                'users.is_admin',
                'users.photo',
                'users.created_at',
                'users.updated_at',
                'users.last_login_at'
            ])
                ->with(['companies:id,name']);

            $companyId = $this->getCurrentCompanyId();
            if ($companyId) {
                $query->join('company_user', 'users.id', '=', 'company_user.user_id')
                    ->where('company_user.company_id', $companyId)
                    ->distinct();
            }

            if ($currentUser && !$currentUser->is_admin) {
                $permissions = $this->getUserPermissionsForCompany($currentUser);
                $hasViewAll = in_array('users_view_all', $permissions) || in_array('users_view', $permissions);
                if (!$hasViewAll && in_array('users_view_own', $permissions)) {
                    $query->where('users.id', $currentUser->id);
                }
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('users.name', 'like', "%{$search}%")
                        ->orWhere('users.surname', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%")
                        ->orWhere('users.position', 'like', "%{$search}%");
                });
            }

            if ($statusFilter !== null) {
                $query->where('users.is_active', $statusFilter);
            }

            $paginated = $query->orderBy('users.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);

            $companyId = $this->getCurrentCompanyId();
            $users = $paginated->getCollection();
            $userIds = $users->pluck('id');

            $salariesMap = [];
            if ($userIds->isNotEmpty() && $companyId) {
                $salaries = EmployeeSalary::whereIn('user_id', $userIds)
                    ->where('company_id', $companyId)
                    ->with('currency:id,code,symbol,name')
                    ->orderBy('user_id')
                    ->orderBy('start_date', 'desc')
                    ->get()
                    ->groupBy('user_id');

                foreach ($salaries as $userId => $userSalaries) {
                    $lastSalary = $userSalaries->first();
                    $salariesMap[$userId] = [
                        'id' => $lastSalary->id,
                        'amount' => $lastSalary->amount,
                        'start_date' => $lastSalary->start_date,
                        'end_date' => $lastSalary->end_date,
                        'currency' => $lastSalary->currency,
                    ];
                }
            }

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
        }, (int)$page);
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
            'users.name',
            'users.surname',
            'users.email',
            'users.is_active',
            'users.hire_date',
            'users.birthday',
            'users.position',
            'users.is_admin',
            'users.photo',
            'users.created_at',
            'users.last_login_at'
        ])->with(['companies:id,name']);

        if ($companyId) {
            $query->join('company_user', 'users.id', '=', 'company_user.user_id')
                ->where('company_user.company_id', $companyId)
                ->distinct();
        }

        if ($currentUser && !$currentUser->is_admin) {
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
            ->with('currency:id,code,symbol,name')
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
            ->whereIn('user_id', $userIds)
            ->where('company_id', $companyId)
            ->get()
            ->groupBy('user_id');

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
            ->whereIn('user_id', $userIds)
            ->select('user_id', 'company_id', 'role_id')
            ->get()
            ->groupBy('user_id');

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
                throw new \InvalidArgumentException('Пользователь должен быть привязан хотя бы к одной компании');
            }

            $user = new User();
            $user->name     = $data['name'];
            $user->surname  = $data['surname'] ?? null;
            $user->email    = $data['email'];
            $user->password = $data['password'];
            $user->hire_date = $data['hire_date'] ?? null;
            $user->birthday = isset($data['birthday']) && $data['birthday'] ? Carbon::parse($data['birthday'])->format('Y-m-d') : null;
            $user->is_active = $data['is_active'] ?? true;
            $user->position = $data['position'] ?? null;
            $user->is_admin = $data['is_admin'] ?? false;

            if (isset($data['photo'])) {
                $user->photo = $data['photo'];
            }

            $user->save();

            if (isset($data['companies'])) {
                $user->companies()->sync($data['companies']);
            }

            $this->syncUserRoles($user, $data);

            if ($user->companies()->count() === 0) {
                throw new \InvalidArgumentException('Пользователь должен быть привязан хотя бы к одной компании');
            }

            $this->loadUserRelations($user);

            CacheService::invalidateByLike('%users_paginated%');
            CacheService::invalidateByLike('%users_all%');

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
            $user->hire_date = array_key_exists('hire_date', $data) ? $data['hire_date'] : $user->hire_date;
            $user->birthday = array_key_exists('birthday', $data) && $data['birthday']
                ? Carbon::parse($data['birthday'])->format('Y-m-d')
                : (array_key_exists('birthday', $data) ? null : $user->birthday);
            $user->is_active = $data['is_active'] ?? $user->is_active;
            $user->position = array_key_exists('position', $data) ? $data['position'] : $user->position;
            $user->is_admin = $data['is_admin'] ?? $user->is_admin;

            if (isset($data['photo'])) {
                $user->photo = $data['photo'];
            }

            if (!empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();

            if (isset($data['companies'])) {
                $user->companies()->sync($data['companies']);
            }

            $this->syncUserRoles($user, $data);

            if ($user->companies()->count() === 0) {
                throw new \InvalidArgumentException('Пользователь должен быть привязан хотя бы к одной компании');
            }

            $this->loadUserRelations($user);

            CacheService::invalidateByLike('%users_paginated%');
            CacheService::invalidateByLike('%users_all%');

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
        $user->load(['companies']);
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
            throw new \Exception('Нельзя удалить главного администратора (ID: 1)');
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
            [Transaction::class, 'user_id', 'транзакции'],
            [Order::class, 'user_id', 'заказы'],
            [Sale::class, 'user_id', 'продажи'],
            [WhReceipt::class, 'user_id', 'приходы на склад'],
            [WhWriteoff::class, 'user_id', 'списания со склада'],
            [WhMovement::class, 'user_id', 'перемещения между складами'],
            [Project::class, 'user_id', 'проекты'],
            [Client::class, 'employee_id', 'клиенты как сотрудник'],
            [Client::class, 'user_id', 'клиенты'],
            [Category::class, 'user_id', 'категории'],
            [Product::class, 'user_id', 'товары'],
            [Invoice::class, 'user_id', 'счета'],
            [CashTransfer::class, 'user_id', 'переводы между кассами'],
            [TransactionCategory::class, 'user_id', 'категории транзакций'],
            [OrderStatusCategory::class, 'user_id', 'категории статусов заказов'],
            [ProjectStatus::class, 'user_id', 'статусы проектов'],
            [Template::class, 'user_id', 'шаблоны'],
            [Comment::class, 'user_id', 'комментарии'],
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

        return CacheService::remember($cacheKey, function () use ($userId, $companyId) {
            $query = EmployeeSalary::where('user_id', $userId)
                ->with(['currency:id,code,symbol,name']);

            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            return $query->orderBy('start_date', 'desc')->get();
        }, $this->getCacheTTL('user_data'));
    }

    /**
     * Создать зарплату сотрудника
     *
     * @param int $userId ID пользователя
     * @param array $data Данные зарплаты
     * @return EmployeeSalary
     */
    public function createSalary($userId, array $data)
    {
        $companyId = $this->getCurrentCompanyId();

        return DB::transaction(function () use ($userId, $data, $companyId) {
            $startDate = $data['start_date'];
            $endDate = $data['end_date'] ?? null;

            if ($endDate === null) {
                $activeSalary = EmployeeSalary::where('user_id', $userId)
                    ->where('company_id', $companyId)
                    ->whereNull('end_date')
                    ->first();

                if ($activeSalary) {
                    throw new \Exception('У сотрудника уже есть активная зарплата. Сначала закройте текущую зарплату.');
                }
            }

            $conflictingSalary = EmployeeSalary::where('user_id', $userId)
                ->where('company_id', $companyId)
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
                throw new \Exception('Зарплата пересекается по датам с существующей зарплатой. Проверьте даты начала и окончания.');
            }

            $salary = EmployeeSalary::create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'amount' => $data['amount'],
                'currency_id' => $data['currency_id'],
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

            $newStartDate = $data['start_date'] ?? $salary->start_date;
            $newEndDate = array_key_exists('end_date', $data) ? $data['end_date'] : $salary->end_date;

            if (array_key_exists('end_date', $data)) {
                if ($newEndDate === null) {
                    $newerActiveSalary = EmployeeSalary::where('user_id', $salary->user_id)
                        ->where('company_id', $salary->company_id)
                        ->whereNull('end_date')
                        ->where('start_date', '>', $newStartDate)
                        ->where('id', '!=', $salaryId)
                        ->first();

                    if ($newerActiveSalary) {
                        throw new \Exception('Нельзя разблокировать эту зарплату, так как есть более новая активная зарплата. Сначала закройте более новую зарплату.');
                    }

                    $activeSalary = EmployeeSalary::where('user_id', $salary->user_id)
                        ->where('company_id', $salary->company_id)
                        ->whereNull('end_date')
                        ->where('id', '!=', $salaryId)
                        ->first();

                    if ($activeSalary) {
                        throw new \Exception('У сотрудника уже есть активная зарплата. Сначала закройте текущую зарплату.');
                    }
                } else {
                    $newerActiveSalary = EmployeeSalary::where('user_id', $salary->user_id)
                        ->where('company_id', $salary->company_id)
                        ->whereNull('end_date')
                        ->where('start_date', '>', $newStartDate)
                        ->where('id', '!=', $salaryId)
                        ->first();

                    if ($newerActiveSalary) {
                        throw new \Exception('Нельзя закрыть эту зарплату, так как есть более новая активная зарплата. Сначала закройте более новую зарплату.');
                    }
                }
            }

            if (isset($data['start_date']) || array_key_exists('end_date', $data)) {
                $conflictingSalary = EmployeeSalary::where('user_id', $salary->user_id)
                    ->where('company_id', $salary->company_id)
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
                    throw new \Exception('Зарплата пересекается по датам с существующей зарплатой. Проверьте даты начала и окончания.');
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

        return CacheService::remember($cacheKey, function () use ($userId) {
            $query = Client::where('employee_id', $userId)
                ->where('client_type', 'employee')
                ->select('id', 'balance', 'company_id');

            $query = $this->addCompanyFilterDirect($query, 'clients');
            $client = $query->first();

            if (!$client) {
                return null;
            }

            return [
                'client_id' => $client->id,
                'balance' => $client->balance ?? 0,
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
}
