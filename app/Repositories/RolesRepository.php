<?php

namespace App\Repositories;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;

class RolesRepository extends BaseRepository
{
    /**
     * Получить роли с пагинацией
     *
     * @param int $page Номер страницы
     * @param int $perPage Количество записей на страницу
     * @param string|null $search Поисковый запрос
     * @param int|null $companyId ID компании
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($page = 1, $perPage = 20, $search = null, $companyId = null)
    {
        $query = Role::where('guard_name', 'api')
            ->with('permissions:id,name')
            ->select(['id', 'name', 'guard_name', 'company_id', 'created_at', 'updated_at']);

        $this->applyCompanyFilter($query, $companyId);

        if ($search && ($searchTerm = trim($search))) {
            $query->where('name', 'like', '%' . $searchTerm . '%');
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
    }

    /**
     * Получить все роли
     *
     * @param int|null $companyId ID компании
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($companyId = null)
    {
        $query = Role::where('guard_name', 'api')
            ->with('permissions:id,name')
            ->select(['id', 'name', 'guard_name', 'company_id']);

        $this->applyCompanyFilter($query, $companyId);

        return $query->orderBy('name')->get();
    }

    /**
     * Получить все роли для всех компаний (глобальные + для всех компаний)
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItemsForAllCompanies()
    {
        return Role::where('guard_name', 'api')
            ->with('permissions:id,name')
            ->select(['id', 'name', 'guard_name', 'company_id'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Создать роль
     *
     * @param array $data Данные роли
     * @param int|null $companyId ID компании
     * @return Role
     * @throws \Exception
     */
    public function createItem(array $data, $companyId = null)
    {
        $companyId = $companyId ?? $this->getCurrentCompanyId();

        return DB::transaction(function () use ($data, $companyId) {
            $name = trim($data['name'] ?? '');
            $guardName = 'api';

            // Проверяем уникальность с учетом company_id
            $existingRole = Role::where('name', $name)
                ->where('guard_name', $guardName)
                ->where('company_id', $companyId ? (int)$companyId : null)
                ->first();

            if ($existingRole) {
                throw new \Exception("Роль с именем '{$name}' уже существует в этой компании");
            }

            $role = new Role();
            $role->name = $name;
            $role->guard_name = $guardName;
            $role->company_id = $companyId ? (int)$companyId : null;
            $role->save();

            $this->syncRolePermissions($role, $data['permissions'] ?? []);

            return $role->load('permissions');
        });
    }

    /**
     * Обновить роль
     *
     * @param int $id ID роли
     * @param array $data Данные для обновления
     * @param int|null $companyId ID компании
     * @return Role
     * @throws \Exception
     */
    public function updateItem($id, array $data, $companyId = null)
    {
        return DB::transaction(function () use ($id, $data, $companyId) {
            $query = Role::where('guard_name', 'api');
            $this->applyCompanyFilter($query, $companyId);
            $role = $query->findOrFail($id);

            if (isset($data['name'])) {
                $newName = trim($data['name']);
                /** @var \App\Models\User|null $user */
                $user = auth('api')->user();
                $isAdmin = $user && $user->is_admin;

                if (!$isAdmin && $role->name === 'admin' && $newName !== 'admin') {
                    throw new \Exception('Нельзя изменить название роли администратора');
                }
                $role->name = $newName;
                $role->save();
            }

            if (isset($data['permissions'])) {
                $this->syncRolePermissions($role, $data['permissions']);
            }

            return $role->load('permissions');
        });
    }

    /**
     * Нормализовать массив разрешений (убрать дубликаты, удалить _own если есть _all)
     *
     * @param array $permissions Массив разрешений
     * @return array Нормализованный массив разрешений
     */
    protected function normalizePermissions(array $permissions): array
    {
        if (empty($permissions)) {
            return [];
        }

        // Фильтруем и обрезаем разрешения
        $normalized = array_filter(array_map('trim', $permissions), function ($perm) {
            return !empty($perm) && is_string($perm);
        });

        if (empty($normalized)) {
            return [];
        }

        $normalized = array_values(array_unique($normalized));
        $normalizedMap = array_flip($normalized);
        $toRemove = [];

        // Если есть _all, удаляем соответствующий _own
        foreach ($normalized as $perm) {
            if (str_ends_with($perm, '_own')) {
                $allPerm = str_replace('_own', '_all', $perm);
                if (isset($normalizedMap[$allPerm])) {
                    $toRemove[] = $perm;
                }
            }
        }

        return array_values(array_diff($normalized, $toRemove));
    }

    /**
     * Валидировать разрешения (оставить только существующие в БД)
     *
     * @param array $permissions Массив разрешений
     * @return array Валидированный массив разрешений
     */
    protected function validatePermissions(array $permissions): array
    {
        if (empty($permissions)) {
            return [];
        }

        // Фильтруем валидные разрешения
        $permissions = array_filter($permissions, fn($p) => is_string($p) && !empty(trim($p)));

        if (empty($permissions)) {
            return [];
        }

        $validPermissionNames = Permission::where('guard_name', 'api')
            ->pluck('name')
            ->toArray();

        return array_values(array_intersect($permissions, $validPermissionNames));
    }

    /**
     * Синхронизировать разрешения роли
     *
     * @param Role $role Роль
     * @param array|mixed $permissions Массив разрешений
     * @return void
     */
    protected function syncRolePermissions(Role $role, $permissions): void
    {
        if (!is_array($permissions)) {
            $role->syncPermissions([]);
            return;
        }

        $permissions = $this->normalizePermissions($permissions);
        $permissions = $this->validatePermissions($permissions);

        $permissionModels = Permission::whereIn('name', $permissions)
            ->where('guard_name', 'api')
            ->get();

        $role->syncPermissions($permissionModels);
    }

    /**
     * Удалить роль
     *
     * @param int $id ID роли
     * @param int|null $companyId ID компании
     * @return bool
     * @throws \Exception
     */
    public function deleteItem($id, $companyId = null)
    {
        if (empty($id)) {
            throw new \Exception('ID роли не указан');
        }

        $query = Role::where('guard_name', 'api');
        $this->applyCompanyFilter($query, $companyId);
        $role = $query->findOrFail($id);

        if ($role->name === 'admin') {
            throw new \Exception('Нельзя удалить роль администратора');
        }

        $usersCount = $role->users()->count();
        if ($usersCount > 0) {
            throw new \Exception("Нельзя удалить роль, которая назначена {$usersCount} пользователям");
        }

        $role->delete();
        return true;
    }

    /**
     * Получить роль по ID
     *
     * @param int $id ID роли
     * @param int|null $companyId ID компании
     * @return Role
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getItem($id, $companyId = null)
    {
        $query = Role::where('guard_name', 'api')
            ->with('permissions:id,name');

        $this->applyCompanyFilter($query, $companyId);

        return $query->findOrFail($id);
    }

    /**
     * Создать роли для компании (admin и basement_worker)
     *
     * @param int $companyId ID компании
     * @return void
     */
    public function createDefaultRolesForCompany(int $companyId): void
    {
        $allPermissions = Permission::where('guard_name', 'api')->get();

        $adminRole = Role::firstOrCreate(
            [
                'name' => 'admin',
                'guard_name' => 'api',
                'company_id' => $companyId,
            ],
            [
                'name' => 'admin',
                'guard_name' => 'api',
                'company_id' => $companyId,
            ]
        );
        $adminRole->syncPermissions($allPermissions);

        $basementPermissions = [
            'orders_view',
            'orders_create',
            'orders_update',
            'clients_view',
            'clients_create',
            'clients_update',
            'products_view',
            'projects_view',
            'categories_view',
            'order_statuses_view',
            'cash_registers_view',
            'cash_registers_view_all',  // Доступ ко всем кассам
            'warehouses_view',
            'warehouses_view_all',      // Доступ ко всем складам
        ];

        $basementPermissionModels = Permission::whereIn('name', $basementPermissions)
            ->where('guard_name', 'api')
            ->get();

        $basementRole = Role::firstOrCreate(
            [
                'name' => 'basement_worker',
                'guard_name' => 'api',
                'company_id' => $companyId,
            ],
            [
                'name' => 'basement_worker',
                'guard_name' => 'api',
                'company_id' => $companyId,
            ]
        );
        $basementRole->syncPermissions($basementPermissionModels);
    }

}

