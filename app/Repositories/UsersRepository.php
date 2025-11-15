<?php

namespace App\Repositories;

use App\Models\User;
use App\Services\CacheService;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
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


    /**
     * Получить всех пользователей
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
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

    /**
     * Создать пользователя
     *
     * @param array $data Данные пользователя
     * @return User
     * @throws \Exception
     */
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

            CacheService::invalidateByLike('%users_paginated%');
            CacheService::invalidateByLike('%users_all%');

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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

            CacheService::invalidateByLike('%users_paginated%');
            CacheService::invalidateByLike('%users_all%');

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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

            foreach ($data['company_roles'] as $companyRole) {
                if (isset($companyRole['company_id']) && isset($companyRole['role_ids']) && is_array($companyRole['role_ids'])) {
                    if (empty($selectedCompanyIds) || in_array($companyRole['company_id'], $selectedCompanyIds)) {
                        foreach ($companyRole['role_ids'] as $roleName) {
                            $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
                            if ($role) {
                                $user->companyRoles()->attach($role->id, ['company_id' => $companyRole['company_id']]);
                            }
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

        DB::beginTransaction();
        try {
            $user->companies()->detach();
            $user->warehouses()->detach();
            $user->projects()->detach();
            $user->categories()->detach();
            $user->roles()->detach();

            $user->companyRoles()->detach();
            CashRegisterUser::where('user_id', $user->id)->delete();

            $user->delete();

            DB::commit();

            CacheService::invalidateByLike('%users_paginated%');
            CacheService::invalidateByLike('%users_all%');

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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

        $transactionsCount = Transaction::where('user_id', $user->id)->count();
        if ($transactionsCount > 0) {
            $relatedData[] = "транзакции ({$transactionsCount})";
        }

        $ordersCount = Order::where('user_id', $user->id)->count();
        if ($ordersCount > 0) {
            $relatedData[] = "заказы ({$ordersCount})";
        }

        $salesCount = Sale::where('user_id', $user->id)->count();
        if ($salesCount > 0) {
            $relatedData[] = "продажи ({$salesCount})";
        }

        $receiptsCount = WhReceipt::where('user_id', $user->id)->count();
        if ($receiptsCount > 0) {
            $relatedData[] = "приходы на склад ({$receiptsCount})";
        }

        $writeoffsCount = WhWriteoff::where('user_id', $user->id)->count();
        if ($writeoffsCount > 0) {
            $relatedData[] = "списания со склада ({$writeoffsCount})";
        }

        $movementsCount = WhMovement::where('user_id', $user->id)->count();
        if ($movementsCount > 0) {
            $relatedData[] = "перемещения между складами ({$movementsCount})";
        }

        $projectsCount = Project::where('user_id', $user->id)->count();
        if ($projectsCount > 0) {
            $relatedData[] = "проекты ({$projectsCount})";
        }

        $clientsAsEmployeeCount = Client::where('employee_id', $user->id)->count();
        if ($clientsAsEmployeeCount > 0) {
            $relatedData[] = "клиенты как сотрудник ({$clientsAsEmployeeCount})";
        }

        $clientsAsUserCount = Client::where('user_id', $user->id)->count();
        if ($clientsAsUserCount > 0) {
            $relatedData[] = "клиенты ({$clientsAsUserCount})";
        }

        $categoriesCount = Category::where('user_id', $user->id)->count();
        if ($categoriesCount > 0) {
            $relatedData[] = "категории ({$categoriesCount})";
        }

        $productsCount = Product::where('user_id', $user->id)->count();
        if ($productsCount > 0) {
            $relatedData[] = "товары ({$productsCount})";
        }

        $invoicesCount = Invoice::where('user_id', $user->id)->count();
        if ($invoicesCount > 0) {
            $relatedData[] = "счета ({$invoicesCount})";
        }

        $cashTransfersCount = CashTransfer::where('user_id', $user->id)->count();
        if ($cashTransfersCount > 0) {
            $relatedData[] = "переводы между кассами ({$cashTransfersCount})";
        }

        $transactionCategoriesCount = TransactionCategory::where('user_id', $user->id)->count();
        if ($transactionCategoriesCount > 0) {
            $relatedData[] = "категории транзакций ({$transactionCategoriesCount})";
        }

        $orderStatusCategoriesCount = OrderStatusCategory::where('user_id', $user->id)->count();
        if ($orderStatusCategoriesCount > 0) {
            $relatedData[] = "категории статусов заказов ({$orderStatusCategoriesCount})";
        }

        $projectStatusesCount = ProjectStatus::where('user_id', $user->id)->count();
        if ($projectStatusesCount > 0) {
            $relatedData[] = "статусы проектов ({$projectStatusesCount})";
        }

        $templatesCount = Template::where('user_id', $user->id)->count();
        if ($templatesCount > 0) {
            $relatedData[] = "шаблоны ({$templatesCount})";
        }

        $commentsCount = Comment::where('user_id', $user->id)->count();
        if ($commentsCount > 0) {
            $relatedData[] = "комментарии ({$commentsCount})";
        }

        return $relatedData;
    }
}
