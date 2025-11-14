<?php

namespace App\Repositories;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;

class RolesRepository extends BaseRepository
{
    public function getItemsWithPagination($page = 1, $perPage = 20, $search = null)
    {
        $query = Role::where('guard_name', 'api')
            ->with('permissions:id,name')
            ->select(['id', 'name', 'guard_name', 'created_at', 'updated_at']);

        if ($search) {
            $searchTerm = trim($search);
            if ($searchTerm) {
                $query->where('name', 'like', '%' . $searchTerm . '%');
            }
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
    }

    public function getAllItems()
    {
        return Role::where('guard_name', 'api')
            ->with('permissions:id,name')
            ->select(['id', 'name', 'guard_name'])
            ->orderBy('name')
            ->get();
    }

    public function createItem(array $data)
    {
        DB::beginTransaction();
        try {
            $role = Role::create([
                'name' => trim($data['name'] ?? ''),
                'guard_name' => 'api',
            ]);

            $this->syncRolePermissions($role, $data['permissions'] ?? []);

            DB::commit();

            return $role->load('permissions');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateItem($id, array $data)
    {
        DB::beginTransaction();
        try {
            $role = Role::where('guard_name', 'api')->findOrFail($id);

            if (isset($data['name'])) {
                $newName = trim($data['name']);
                if ($role->name === 'admin' && $newName !== 'admin') {
                    throw new \Exception('Нельзя изменить название роли администратора');
                }
                $role->name = $newName;
                $role->save();
            }

            if (isset($data['permissions'])) {
                $this->syncRolePermissions($role, $data['permissions']);
            }

            DB::commit();

            return $role->load('permissions');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function normalizePermissions(array $permissions): array
    {
        if (empty($permissions)) {
            return [];
        }

        $normalized = [];
        foreach ($permissions as $perm) {
            $trimmed = trim($perm);
            if (!empty($trimmed) && is_string($perm)) {
                $normalized[] = $trimmed;
            }
        }

        if (empty($normalized)) {
            return [];
        }

        $normalized = array_unique($normalized);
        $normalizedMap = array_flip($normalized);
        $toRemove = [];

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

    protected function validatePermissions(array $permissions): array
    {
        if (empty($permissions)) {
            return [];
        }

        $permissions = array_filter($permissions, fn($p) => is_string($p) && !empty(trim($p)));

        if (empty($permissions)) {
            return [];
        }

        $validPermissionNames = Permission::where('guard_name', 'api')
            ->pluck('name')
            ->toArray();

        return array_values(array_intersect($permissions, $validPermissionNames));
    }

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

    public function deleteItem($id)
    {
        if (empty($id)) {
            throw new \Exception('ID роли не указан');
        }

        $role = Role::where('guard_name', 'api')->findOrFail($id);

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

    public function getItem($id)
    {
        return Role::where('guard_name', 'api')
            ->with('permissions:id,name')
            ->findOrFail($id);
    }
}

