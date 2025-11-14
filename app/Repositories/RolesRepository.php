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
            $query->where('name', 'like', "%{$search}%");
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
                'name' => $data['name'],
                'guard_name' => 'api',
            ]);

            if (isset($data['permissions']) && is_array($data['permissions'])) {
                $permissions = Permission::whereIn('name', $data['permissions'])
                    ->where('guard_name', 'api')
                    ->get();
                $role->syncPermissions($permissions);
            }

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

            $role->name = $data['name'] ?? $role->name;
            $role->save();

            if (isset($data['permissions']) && is_array($data['permissions'])) {
                $permissions = Permission::whereIn('name', $data['permissions'])
                    ->where('guard_name', 'api')
                    ->get();
                $role->syncPermissions($permissions);
            }

            DB::commit();

            return $role->load('permissions');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function deleteItem($id)
    {
        $role = Role::where('guard_name', 'api')->findOrFail($id);

        if ($role->name === 'admin') {
            throw new \Exception('Нельзя удалить роль администратора');
        }

        // Проверяем, используется ли роль
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

