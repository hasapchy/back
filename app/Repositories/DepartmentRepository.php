<?php

namespace App\Repositories;

use App\Models\Department;
use App\Models\DepartmentUser;
use App\Services\CacheService;

class DepartmentRepository extends BaseRepository
{
    /**
     * Получить департаменты с пагинацией
     */
    public function getItemsWithPagination($userId, $perPage = 20, $page = 1)
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('departments_paginated', [$userId, $perPage, $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userId, $perPage, $page) {
            $query = Department::with([
                'users:id,name,surname,email,position,photo',
                'head:id,name,surname,email,position,photo',
                'deputyHead:id,name,surname,email,position,photo',
                'company:id,name',
            ]);

            if ($this->shouldApplyUserFilter('departments')) {
                $filterUserId = $this->getFilterUserIdForPermission('departments', $userId);
                $departmentIds = \DB::table('department_user')
                    ->where('user_id', $filterUserId)
                    ->pluck('department_id')
                    ->toArray();

                if (empty($departmentIds)) {
                    return collect([])->paginate($perPage);
                }

                $query->whereIn('id', $departmentIds);
            }

            $query = $this->addCompanyFilterDirect($query, 'departments');

            return $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int) $page);
        }, (int) $page);
    }

    /**
     * Получить все департаменты
     */
    public function getAllItems($userId)
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('departments_all', [$userId, $currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function () use ($userId) {
            $query = Department::with([
                'users:id,name,surname,email,position,photo',
                'head:id,name,surname,email,position,photo',
                'deputyHead:id,name,surname,email,position,photo',
                'company:id,name',
            ]);

            if ($this->shouldApplyUserFilter('departments')) {
                $filterUserId = $this->getFilterUserIdForPermission('departments', $userId);
                $departmentIds = \DB::table('department_user')
                    ->where('user_id', $filterUserId)
                    ->pluck('department_id')
                    ->toArray();

                if (empty($departmentIds)) {
                    return collect([]);
                }

                $query->whereIn('id', $departmentIds);
            }

            $query = $this->addCompanyFilterDirect($query, 'departments');

            return $query->orderBy('title', 'asc')->get();
        });
    }

    /**
     * Создать департамент
     */
    public function createItem(array $data)
    {
        $department = Department::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'head_id' => $data['head_id'] ?? null,
            'deputy_head_id' => $data['deputy_head_id'] ?? null,
            'company_id' => $this->getCurrentCompanyId(),
        ]);

        if (array_key_exists('users', $data)) {
            $this->syncUsers($department->id, $data['users'] ?? []);
        }

        CacheService::invalidateDepartmentsCache();

        return $department->load([
            'users:id,name,surname,email,position,photo',
            'head:id,name,surname,email,position,photo',
            'deputyHead:id,name,surname,email,position,photo',
            'company:id,name',
        ]);
    }

    /**
     * Обновить департамент
     */
    public function updateItem(int $id, array $data)
    {
        $department = Department::findOrFail($id);

        $department->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'head_id' => $data['head_id'] ?? null,
            'deputy_head_id' => $data['deputy_head_id'] ?? null,
        ]);

        if (array_key_exists('users', $data)) {
            $this->syncUsers($department->id, $data['users'] ?? []);
        }

        CacheService::invalidateDepartmentsCache();

        return $department->load([
            'users:id,name,surname,email,position,photo',
            'head:id,name,surname,email,position,photo',
            'deputyHead:id,name,surname,email,position,photo',
            'company:id,name',
        ]);
    }

    /**
     * Удалить департамент
     */
    public function deleteItem(int $id)
    {
        $department = Department::findOrFail($id);
        $department->delete();

        CacheService::invalidateDepartmentsCache();

        return true;
    }

    private function syncUsers(int $departmentId, array $userIds): void
    {
        $this->syncManyToManyUsers(
            DepartmentUser::class,
            'department_id',
            $departmentId,
            $userIds,
            [
                'require_at_least_one' => true,
                'error_message' => 'В департаменте должен быть хотя бы один сотрудник',
            ]
        );
    }
}
