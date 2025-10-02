<?php

namespace App\Repositories;

use App\Models\User;
use App\Services\CacheService;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;

class UsersRepository
{
    public function getItemsWithPagination($page = 1, $perPage = 20, $search = null, $statusFilter = null)
    {
        // Создаем уникальный ключ кэша
        $cacheKey = "users_paginated_{$perPage}_{$search}_{$statusFilter}";

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

    /**
     * Быстрый поиск пользователей с оптимизированным кэшированием
     */
    public function fastSearch($search, $perPage = 20)
    {
        $cacheKey = "users_fast_search_{$search}_{$perPage}";

        return CacheService::rememberSearch($cacheKey, function () use ($search, $perPage) {
            return User::select([
                'users.id',
                'users.name',
                'users.email',
                'users.is_active',
                'users.position',
                'users.photo',
                'users.created_at'
            ])
                ->with(['roles:id,name'])
                ->where(function ($q) use ($search) {
                    $q->where('users.name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%")
                        ->orWhere('users.position', 'like', "%{$search}%");
                })
                ->orderBy('users.created_at', 'desc')
                ->paginate($perPage);
        });
    }

    public function getAllItems()
    {
        $cacheKey = "users_all";

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


            if (isset($data['name'])) {
                $user->name = $data['name'];
            }
            if (isset($data['email'])) {
                $user->email = $data['email'];
            }
            if (isset($data['hire_date'])) {
                $user->hire_date = $data['hire_date'];
            }
            if (isset($data['is_active'])) {
                $user->is_active = $data['is_active'];
            }
            if (isset($data['position'])) {
                $user->position = $data['position'];
            }
            if (isset($data['is_admin'])) {
                $user->is_admin = $data['is_admin'];
            }

            // Обрабатываем фото (как в ProductsRepository)
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
     * Получить пользователя с полными данными
     */
    public function findItem($id)
    {
        $cacheKey = "user_item_{$id}";

        return CacheService::remember($cacheKey, function () use ($id) {
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
                'users.updated_at',
                'users.last_login_at'
            ])
                ->with([
                    'roles:id,name',
                    'permissions:id,name',
                    'companies:id,name'
                ])
                ->find($id);
        }, 1800); // 30 минут
    }

    /**
     * Получить пользователей по ID с оптимизацией
     */
    public function getItemsByIds(array $userIds)
    {
        if (empty($userIds)) {
            return collect();
        }

        $cacheKey = "users_by_ids_" . md5(implode(',', $userIds));

        return CacheService::remember($cacheKey, function () use ($userIds) {
            return User::select([
                'users.id',
                'users.name',
                'users.email',
                'users.is_active',
                'users.position'
            ])
                ->whereIn('users.id', $userIds)
                ->orderBy('users.name')
                ->get();
        }, 1800); // 30 минут
    }

    /**
     * Получить активных пользователей
     */
    public function getActiveUsers()
    {
        $cacheKey = "users_active";

        return CacheService::remember($cacheKey, function () {
            return User::select([
                'users.id',
                'users.name',
                'users.email',
                'users.position'
            ])
                ->where('users.is_active', true)
                ->orderBy('users.name')
                ->get();
        }, 1800); // 30 минут
    }

    /**
     * Получить пользователей по роли
     */
    public function getUsersByRole($roleName)
    {
        $cacheKey = "users_by_role_{$roleName}";

        return CacheService::remember($cacheKey, function () use ($roleName) {
            return User::select([
                'users.id',
                'users.name',
                'users.email',
                'users.is_active',
                'users.position'
            ])
                ->whereHas('roles', function ($query) use ($roleName) {
                    $query->where('name', $roleName);
                })
                ->where('users.is_active', true)
                ->orderBy('users.name')
                ->get();
        }, 1800); // 30 минут
    }

    /**
     * Инвалидация кэша пользователей
     */
    private function invalidateUsersCache()
    {
        // Очищаем кэш, связанный с пользователями
        $keys = [
            'users_paginated_*',
            'users_all*',
            'users_fast_search_*',
            'user_item_*',
            'users_by_role_*',
            'users_active'
        ];

        foreach ($keys as $key) {
            if (str_contains($key, '*')) {
                // Для паттернов с wildcard очищаем весь кэш
                \Illuminate\Support\Facades\Cache::flush();
                break;
            } else {
                \Illuminate\Support\Facades\Cache::forget($key);
            }
        }
    }

    /**
     * Инвалидация кэша конкретного пользователя
     */
    public function invalidateUserCache($userId)
    {
        \Illuminate\Support\Facades\Cache::forget("user_item_{$userId}");
        \Illuminate\Support\Facades\Cache::forget("users_by_ids_*");
        $this->invalidateUsersCache();
    }
}
