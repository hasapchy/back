<?php

namespace App\Repositories;

use App\Models\User;
use App\Services\CacheService;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;

class UsersRepository
{
    /**
     * Получить текущую компанию пользователя из заголовка запроса
     */
    private function getCurrentCompanyId()
    {
        // Получаем company_id из заголовка запроса
        return request()->header('X-Company-ID');
    }

    public function getItemsWithPagination($page = 1, $perPage = 20, $search = null, $statusFilter = null)
    {
        // ✅ Получаем компанию из заголовка для включения в кэш ключ
        $companyId = $this->getCurrentCompanyId() ?? 'default';

        // Создаем уникальный ключ кэша
        $cacheKey = "users_paginated_{$companyId}_{$perPage}_{$search}_{$statusFilter}";

        // Для списка без фильтров используем более длительное кэширование
        $ttl = (!$search && !$statusFilter) ? 1800 : 600; // 30 мин для списка, 10 мин для фильтров

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $statusFilter, $page) {
            $query = User::select([
                'users.id',
                'users.name',
                'users.email',
                'users.is_active',
                'users.hire_date',
                'users.position',
                'users.is_admin',
                'users.photo',
                'users.created_at',
                'users.updated_at',
                'users.last_login_at'
            ])
                ->with([
                    'roles:id,name',
                    'permissions:id,name',
                    'companies:id,name'
                ]);

            // Применяем фильтры
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('users.name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%")
                        ->orWhere('users.position', 'like', "%{$search}%");
                });
            }

            // Фильтрация по статусу
            if ($statusFilter !== null) {
                $query->where('users.is_active', $statusFilter);
            }

            return $query->orderBy('users.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }


    public function getAllItems()
    {
        // ✅ Получаем компанию из заголовка для включения в кэш ключ
        $companyId = $this->getCurrentCompanyId() ?? 'default';

        $cacheKey = "users_all_{$companyId}";

        return CacheService::remember($cacheKey, function () {
            return User::select([
                'users.id',
                'users.name',
                'users.email',
                'users.is_active',
                'users.hire_date',
                'users.position',
                'users.is_admin',
                'users.photo',
                'users.created_at',
                'users.last_login_at'
            ])
                ->with([
                    'roles:id,name',
                    'permissions:id,name',
                    'companies:id,name'
                ])
                ->orderBy('users.created_at', 'desc')
                ->get();
        }, 1800); // 30 минут
    }

    public function createItem(array $data)
    {
        DB::beginTransaction();
        try {
            $user = new User();
            $user->name     = $data['name'];
            $user->email    = $data['email'];
            $user->password = Hash::make($data['password']);
            $user->hire_date = $data['hire_date'] ?? null;
            $user->is_active = $data['is_active'] ?? true;
            $user->position = $data['position'] ?? null;
            $user->is_admin = $data['is_admin'] ?? false;

            if (isset($data['photo'])) {
                $user->photo = $data['photo'];
            }

            $user->save();

            if (isset($data['permissions'])) {
                $user->syncPermissions($data['permissions']);
            }

            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }

            if (isset($data['companies'])) {
                $user->companies()->sync($data['companies']);
            }

            DB::commit();

            // Инвалидируем кэш пользователей
            $this->invalidateUsersCache();

            return $user->load(['permissions', 'roles', 'companies']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateItem($id, array $data)
    {
        DB::beginTransaction();
        try {
            $user = User::findOrFail($id);


            $user->name = $data['name'] ?? $user->name;
            $user->email = $data['email'] ?? $user->email;
            $user->hire_date = $data['hire_date'] ?? $user->hire_date;
            $user->is_active = $data['is_active'] ?? $user->is_active;
            $user->position = $data['position'] ?? $user->position;
            $user->is_admin = $data['is_admin'] ?? $user->is_admin;

            // Обрабатываем фото
            if (isset($data['photo'])) {
                $user->photo = $data['photo'];
            }

            if (!empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();

            if (isset($data['permissions'])) {
                $user->syncPermissions($data['permissions']);
            }

            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }

            if (isset($data['companies'])) {
                $user->companies()->sync($data['companies']);
            }

            DB::commit();

            // Инвалидируем кэш пользователей
            $this->invalidateUsersCache();

            return $user->load(['permissions', 'roles', 'companies']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function deleteItem($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();

            // Инвалидируем кэш пользователей
            $this->invalidateUsersCache();

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getAll()
    {
        $cacheKey = "users_all_simple";

        return CacheService::remember($cacheKey, function () {
            return User::select([
                'users.id',
                'users.name',
                'users.email',
                'users.is_active'
            ])
                ->with(['companies:id,name'])
                ->orderBy('users.name')
                ->get();
        }, 1800); // 30 минут
    }


    /**
     * Инвалидация кэша пользователей
     */
    private function invalidateUsersCache()
    {
        // Используем централизованный метод из CacheService
        CacheService::invalidateByLike('%users_paginated%');
        CacheService::invalidateByLike('%users_all%');
    }

    /**
     * Инвалидация кэша конкретного пользователя
     */
    public function invalidateUserCache($userId)
    {
        $this->invalidateUsersCache();
    }
}
