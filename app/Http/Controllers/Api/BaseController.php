<?php

namespace App\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseRoutingController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use App\Services\PermissionCheckService;
use App\Support\ResolvedCompany;

class BaseController extends BaseRoutingController
{
    use AuthorizesRequests, ValidatesRequests;

    protected ?PermissionCheckService $permissionCheckService = null;

    protected function getPermissionCheckService(): PermissionCheckService
    {
        if ($this->permissionCheckService === null) {
            $this->permissionCheckService = new PermissionCheckService();
        }
        return $this->permissionCheckService;
    }

    /**
     * @return int|null
     */
    protected function getCurrentCompanyId(): ?int
    {
        return ResolvedCompany::fromRequest(request());
    }

    /**
     * Получить авторизованного пользователя API
     *
     * @return \App\Models\User|null
     */
    protected function getAuthenticatedUser()
    {
        return auth('api')->user();
    }

    /**
     * Получить авторизованного пользователя или выбросить исключение
     *
     * @return \App\Models\User
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function requireAuthenticatedUser()
    {
        $user = $this->getAuthenticatedUser();

        if (!$user) {
            abort(401, 'Unauthorized');
        }

        return $user;
    }

    /**
     * Получить ID авторизованного пользователя или выбросить исключение
     *
     * @return int
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function getAuthenticatedUserIdOrFail(): int
    {
        return $this->requireAuthenticatedUser()->id;
    }

    /**
     * Получить права доступа пользователя в виде массива с учетом текущей компании
     *
     * @param \App\Models\User|null $user
     * @param int|null $companyId ID компании (если null, из контекста запроса)
     * @return array
     */
    protected function getUserPermissions($user = null, ?int $companyId = null)
    {
        $user = $user ?? $this->getAuthenticatedUser();

        if (!$user) {
            return [];
        }

        if ($user->is_admin) {
            return \Spatie\Permission\Models\Permission::where('guard_name', 'api')
                ->pluck('name')
                ->toArray();
        }

        $companyId = $companyId ?? $this->getCurrentCompanyId();

        if ($companyId) {
            return $user->getAllPermissionsForCompany((int)$companyId)->pluck('name')->toArray();
        }

        return $user->getAllPermissions()->pluck('name')->toArray();
    }

    /**
     * Проверить, есть ли у пользователя конкретное разрешение
     *
     * @param string $permission
     * @param \App\Models\User|null $user
     * @return bool
     */
    protected function hasPermission($permission, $user = null)
    {
        $user = $user ?? $this->getAuthenticatedUser();

        if ($user && $user->is_admin) {
            return true;
        }

        $permissions = $this->getUserPermissions($user);
        return in_array($permission, $permissions);
    }

    /**
     * Проверить, есть ли у пользователя право на действие с записью (с учетом _all/_own)
     *
     * @param string $resource Ресурс (например, 'users', 'orders')
     * @param string $action Действие (например, 'view', 'update', 'delete')
     * @param mixed $record Запись для проверки (должна иметь creator_id)
     * @param \App\Models\User|null $user Пользователь
     * @return bool
     */
    protected function canPerformAction($resource, $action, $record = null, $user = null)
    {
        $user = $user ?? $this->getAuthenticatedUser();
        if (!$user) {
            return false;
        }

        $permissions = $this->getUserPermissions($user);
        return $this->getPermissionCheckService()->canPerformAction($user, $resource, $action, $record, $permissions);
    }

    /**
     * Проверить доступ к взаиморасчетам по типу клиента
     *
     * @param string $clientType Тип клиента (individual, company, employee, investor)
     * @param \App\Models\User|null $user Пользователь
     * @return bool
     */
    protected function canViewMutualSettlementsByClientType($clientType, $user = null)
    {
        $user = $user ?? $this->getAuthenticatedUser();
        if (!$user) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        $permissions = $this->getUserPermissions($user);
        $config = config("permissions.resources.mutual_settlements");
        $permissionName = $config['custom_permissions']["view_{$clientType}"] ?? "mutual_settlements_view_{$clientType}";

        return in_array($permissionName, $permissions);
    }

    /**
     * Получить доступные типы клиентов для просмотра взаиморасчетов
     *
     * @param \App\Models\User|null $user Пользователь
     * @return array Массив типов клиентов, к которым есть доступ
     */
    protected function getAllowedMutualSettlementsClientTypes($user = null)
    {
        $user = $user ?? $this->getAuthenticatedUser();
        if (!$user) {
            return [];
        }

        if ($user->is_admin) {
            return ['individual', 'company', 'employee', 'investor'];
        }

        $permissions = $this->getUserPermissions($user);
        $allowedTypes = [];
        $config = config("permissions.resources.mutual_settlements");

        if (isset($config['custom_permissions'])) {
            foreach ($config['custom_permissions'] as $key => $permissionName) {
                if (in_array($permissionName, $permissions)) {
                    $type = str_replace('view_', '', $key);
                    $allowedTypes[] = $type;
                }
            }
        }

        return $allowedTypes;
    }

    /**
     * Проверить, есть ли у пользователя хотя бы одно из разрешений
     *
     * @param array $permissions
     * @param \App\Models\User|null $user
     * @return bool
     */
    protected function hasAnyPermission(array $permissions, $user = null)
    {
        $user = $user ?? $this->getAuthenticatedUser();

        if ($user && $user->is_admin) {
            return true;
        }

        $userPermissions = $this->getUserPermissions($user);

        foreach ($permissions as $permission) {
            if (in_array($permission, $userPermissions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $data
     * @return JsonResponse
     */
    protected function successResponse($data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return $this->messageResponse($status, $message, $data);
    }

    /**
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $items
     * @return JsonResponse
     */
    protected function paginatedResponse($items): JsonResponse
    {
        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Выполнить операцию в транзакции БД
     *
     * @param callable $callback
     * @return mixed
     * @throws \Throwable
     */
    protected function transaction(callable $callback)
    {
        return DB::transaction($callback);
    }

    /**
     * Проверить, является ли пользователь владельцем записи или админом
     *
     * @param Model $model Модель для проверки
     * @param string $userIdField Поле с ID пользователя (по умолчанию 'creator_id')
     * @param \App\Models\User|null $user Пользователь (по умолчанию текущий)
     * @return bool
     */
    protected function isOwnerOrAdmin(Model $model, string $userIdField = 'creator_id', $user = null): bool
    {
        $user = $user ?? $this->requireAuthenticatedUser();

        if ($user->is_admin) {
            return true;
        }

        return $model->$userIdField === $user->id;
    }

    /**
     * Проверить, является ли пользователь владельцем записи или админом, или выбросить исключение
     *
     * @param Model $model Модель для проверки
     * @param string $userIdField Поле с ID пользователя (по умолчанию 'creator_id')
     * @param \App\Models\User|null $user Пользователь (по умолчанию текущий)
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function requireOwnerOrAdmin(Model $model, string $userIdField = 'creator_id', $user = null): void
    {
        if (!$this->isOwnerOrAdmin($model, $userIdField, $user)) {
            abort(403, 'У вас нет прав на эту операцию');
        }
    }

    /**
     * Вернуть ответ с ошибкой валидации
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return \Illuminate\Http\JsonResponse
     */
    protected function validationErrorResponse($validator): JsonResponse
    {
        return $this->messageResponse(422, 'Ошибка валидации', null, 'message', $validator->errors()->toArray());
    }

    /**
     * Вернуть ответ с ошибкой сервера
     *
     * @param string|null $message
     * @param int $status
     * @return \Illuminate\Http\JsonResponse
     */
    protected function errorResponse(?string $message = null, int $status = 500): JsonResponse
    {
        $defaultMessages = [
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ];

        return $this->messageResponse($status, $message ?? $defaultMessages[$status] ?? 'Error', null, 'error');
    }

    /**
     * @param mixed $data
     * @param array<string, mixed>|null $errors
     * @return JsonResponse
     */
    private function messageResponse(
        int $status,
        ?string $message = null,
        $data = null,
        string $messageKey = 'message',
        ?array $errors = null
    ): JsonResponse {
        $response = [];

        if ($message !== null) {
            $response[$messageKey] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }

    /**
     * Проверить права доступа к кассе
     *
     * @param int|null $cashId ID кассы
     * @return \Illuminate\Http\JsonResponse|null Возвращает ответ с ошибкой, если нет прав, иначе null
     */
    protected function checkCashRegisterAccess(?int $cashId): ?JsonResponse
    {
        if ($cashId) {
            $cashRegister = \App\Models\CashRegister::find($cashId);
            if ($cashRegister) {
                $user = $this->getAuthenticatedUser();

                if ($user && $user->hasRole(config('simple.worker_role'))) {
                    $hasAccessByAssignment = $cashRegister->hasUser($user->id);

                    if ($hasAccessByAssignment) {
                        return null;
                    }

                    $permissions = $this->getUserPermissions($user);
                    $hasSimpleOrderPermission = false;
                    foreach ($permissions as $permission) {
                        if (str_starts_with($permission, 'orders_simple_')) {
                            $hasSimpleOrderPermission = true;
                            break;
                        }
                    }

                    if ($hasSimpleOrderPermission) {
                        return null;
                    }
                }

                if (!$this->canPerformAction('cash_registers', 'view', $cashRegister)) {
                    return $this->errorResponse('У вас нет прав на эту кассу', 403);
                }
            }
        }
        return null;
    }

    /**
     * Проверить права доступа к складу
     *
     * @param int|null $warehouseId ID склада
     * @return \Illuminate\Http\JsonResponse|null Возвращает ответ с ошибкой, если нет прав, иначе null
     */
    protected function checkWarehouseAccess(?int $warehouseId): ?JsonResponse
    {
        if ($warehouseId) {
            $warehouse = \App\Models\Warehouse::find($warehouseId);
            if ($warehouse && !$this->canPerformAction('warehouses', 'view', $warehouse)) {
                return $this->errorResponse('У вас нет прав на этот склад', 403);
            }
        }
        return null;
    }
}

