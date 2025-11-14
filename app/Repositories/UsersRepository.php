<?php

namespace App\Repositories;

use App\Models\User;
use App\Services\CacheService;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;

class UsersRepository extends BaseRepository
{

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
                ->with([
                    'roles:id,name',
                    'permissions:id,name',
                    'companies:id,name'
                ]);

            // Фильтрация по компании: показываем только пользователей, связанных с текущей компанией
            $companyId = $this->getCurrentCompanyId();
            if ($companyId) {
                $query->whereHas('companies', function ($q) use ($companyId) {
                    $q->where('companies.id', $companyId);
                });
            }

            if ($currentUser) {
                $permissions = $this->getUserPermissionsForCompany($currentUser);
                $hasViewAll = in_array('users_view_all', $permissions) || in_array('users_view', $permissions);
                if (!$hasViewAll && in_array('users_view_own', $permissions)) {
                    $query->where('users.id', $currentUser->id);
                }
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('users.name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%")
                        ->orWhere('users.position', 'like', "%{$search}%");
                });
            }

            if ($statusFilter !== null) {
                $query->where('users.is_active', $statusFilter);
            }

            $paginated = $query->orderBy('users.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);

            $companyId = $this->getCurrentCompanyId();
            $paginated->getCollection()->transform(function ($user) use ($companyId) {
                return $this->transformUserWithRelations($user, $companyId);
            });

            return $paginated;
        }, (int)$page);
    }


    public function getAllItems()
    {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('users_all', [$currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function () use ($currentUser, $companyId) {
            $query = User::select([
                'users.id',
                'users.name',
                'users.email',
                'users.is_active',
                'users.hire_date',
                'users.birthday',
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
                ]);

            // Фильтрация по компании: показываем только пользователей, связанных с текущей компанией
            if ($companyId) {
                $query->whereHas('companies', function ($q) use ($companyId) {
                    $q->where('companies.id', $companyId);
                });
            }

            if ($currentUser) {
                $permissions = $this->getUserPermissionsForCompany($currentUser);
                $hasViewAll = in_array('users_view_all', $permissions) || in_array('users_view', $permissions);
                if (!$hasViewAll && in_array('users_view_own', $permissions)) {
                    $query->where('users.id', $currentUser->id);
                }
            }

            $users = $query->orderBy('users.created_at', 'desc')->get();

            return $users->map(function ($user) use ($companyId) {
                return $this->transformUserWithRelations($user, $companyId);
            });
        });
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
            $user->birthday = $data['birthday'] ?? null;
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

            DB::commit();

            $this->loadUserRelations($user);

            $this->invalidateUsersCache();

            return $user;
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
            $user->hire_date = array_key_exists('hire_date', $data) ? $data['hire_date'] : $user->hire_date;
            $user->birthday = array_key_exists('birthday', $data) ? $data['birthday'] : $user->birthday;
            $user->is_active = $data['is_active'] ?? $user->is_active;
            $user->position = array_key_exists('position', $data) ? $data['position'] : $user->position;
            $user->is_admin = $data['is_admin'] ?? $user->is_admin;

            if (isset($data['photo'])) {
                $user->photo = $data['photo'];
            }

            if (!empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();

            if (isset($data['companies'])) {
                $user->companies()->sync($data['companies']);
            }

            $this->syncUserRoles($user, $data);

            DB::commit();

            $this->loadUserRelations($user);

            $this->invalidateUsersCache();

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function syncUserRoles(User $user, array $data): void
    {
        if (isset($data['company_roles']) && is_array($data['company_roles'])) {
            DB::table('company_user_role')->where('user_id', $user->id)->delete();

            $selectedCompanyIds = isset($data['companies']) && is_array($data['companies']) ? $data['companies'] : [];

            foreach ($data['company_roles'] as $companyRole) {
                if (isset($companyRole['company_id']) && isset($companyRole['role_ids']) && is_array($companyRole['role_ids'])) {
                    if (empty($selectedCompanyIds) || in_array($companyRole['company_id'], $selectedCompanyIds)) {
                        foreach ($companyRole['role_ids'] as $roleName) {
                            $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
                            if ($role) {
                                DB::table('company_user_role')->insert([
                                    'company_id' => $companyRole['company_id'],
                                    'user_id' => $user->id,
                                    'role_id' => $role->id,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                }
            }
        } elseif (isset($data['roles'])) {
            $user->syncRoles($data['roles']);
        }
    }

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

    protected function transformUserWithRelations(User $user, ?int $companyId): User
    {
        $user->setRelation('permissions', $companyId ? $user->getAllPermissionsForCompany((int)$companyId) : $user->getAllPermissions());
        if ($companyId) {
            $user->setRelation('roles', $user->getRolesForCompany((int)$companyId));
        }
        $user->company_roles = $user->getAllCompanyRoles();
        return $user;
    }

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

        DB::beginTransaction();
        try {
            $user->companies()->detach();
            $user->warehouses()->detach();
            $user->projects()->detach();
            $user->categories()->detach();
            $user->roles()->detach();

            DB::table('company_user_role')->where('user_id', $user->id)->delete();
            DB::table('cash_register_users')->where('user_id', $user->id)->delete();

            $user->delete();

            DB::commit();

            $this->invalidateUsersCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function checkUserRelatedData(User $user): array
    {
        $relatedData = [];

        $transactionsCount = DB::table('transactions')->where('user_id', $user->id)->count();
        if ($transactionsCount > 0) {
            $relatedData[] = "транзакции ({$transactionsCount})";
        }

        $ordersCount = DB::table('orders')->where('user_id', $user->id)->count();
        if ($ordersCount > 0) {
            $relatedData[] = "заказы ({$ordersCount})";
        }

        $salesCount = DB::table('sales')->where('user_id', $user->id)->count();
        if ($salesCount > 0) {
            $relatedData[] = "продажи ({$salesCount})";
        }

        $receiptsCount = DB::table('wh_receipts')->where('user_id', $user->id)->count();
        if ($receiptsCount > 0) {
            $relatedData[] = "приходы на склад ({$receiptsCount})";
        }

        $writeoffsCount = DB::table('wh_writeoffs')->where('user_id', $user->id)->count();
        if ($writeoffsCount > 0) {
            $relatedData[] = "списания со склада ({$writeoffsCount})";
        }

        $movementsCount = DB::table('wh_movements')->where('user_id', $user->id)->count();
        if ($movementsCount > 0) {
            $relatedData[] = "перемещения между складами ({$movementsCount})";
        }

        $projectsCount = DB::table('projects')->where('user_id', $user->id)->count();
        if ($projectsCount > 0) {
            $relatedData[] = "проекты ({$projectsCount})";
        }

        $orderProductsCount = DB::table('order_products')->where('user_id', $user->id)->count();
        if ($orderProductsCount > 0) {
            $relatedData[] = "товары в заказах ({$orderProductsCount})";
        }

        $clientsAsEmployeeCount = DB::table('clients')->where('employee_id', $user->id)->count();
        if ($clientsAsEmployeeCount > 0) {
            $relatedData[] = "клиенты как сотрудник ({$clientsAsEmployeeCount})";
        }

        $clientsAsUserCount = DB::table('clients')->where('user_id', $user->id)->count();
        if ($clientsAsUserCount > 0) {
            $relatedData[] = "клиенты ({$clientsAsUserCount})";
        }

        $categoriesCount = DB::table('categories')->where('user_id', $user->id)->count();
        if ($categoriesCount > 0) {
            $relatedData[] = "категории ({$categoriesCount})";
        }

        $productsCount = DB::table('products')->where('user_id', $user->id)->count();
        if ($productsCount > 0) {
            $relatedData[] = "товары ({$productsCount})";
        }

        $invoicesCount = DB::table('invoices')->where('user_id', $user->id)->count();
        if ($invoicesCount > 0) {
            $relatedData[] = "счета ({$invoicesCount})";
        }

        $cashTransfersCount = DB::table('cash_transfers')->where('user_id', $user->id)->count();
        if ($cashTransfersCount > 0) {
            $relatedData[] = "переводы между кассами ({$cashTransfersCount})";
        }

        $transactionCategoriesCount = DB::table('transaction_categories')->where('user_id', $user->id)->count();
        if ($transactionCategoriesCount > 0) {
            $relatedData[] = "категории транзакций ({$transactionCategoriesCount})";
        }

        $orderStatusCategoriesCount = DB::table('order_status_categories')->where('user_id', $user->id)->count();
        if ($orderStatusCategoriesCount > 0) {
            $relatedData[] = "категории статусов заказов ({$orderStatusCategoriesCount})";
        }

        $projectStatusesCount = DB::table('project_statuses')->where('user_id', $user->id)->count();
        if ($projectStatusesCount > 0) {
            $relatedData[] = "статусы проектов ({$projectStatusesCount})";
        }

        $templatesCount = DB::table('templates')->where('user_id', $user->id)->count();
        if ($templatesCount > 0) {
            $relatedData[] = "шаблоны ({$templatesCount})";
        }

        $orderAfCount = DB::table('order_af')->where('user_id', $user->id)->count();
        if ($orderAfCount > 0) {
            $relatedData[] = "дополнительные поля заказов ({$orderAfCount})";
        }

        $commentsCount = DB::table('comments')->where('user_id', $user->id)->count();
        if ($commentsCount > 0) {
            $relatedData[] = "комментарии ({$commentsCount})";
        }

        return $relatedData;
    }


    private function invalidateUsersCache()
    {
        CacheService::invalidateByLike('%users_paginated%');
        CacheService::invalidateByLike('%users_all%');
    }

    public function invalidateUserCache($userId)
    {
        $this->invalidateUsersCache();
    }
}
