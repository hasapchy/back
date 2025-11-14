<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Получить текущую компанию пользователя из заголовка запроса
     */
    protected function getCurrentCompanyId()
    {
        return request()->header('X-Company-ID');
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
     * Получить ID авторизованного пользователя
     *
     * @deprecated Используйте getAuthenticatedUserIdOrFail() для избежания проверки instanceof JsonResponse
     * @return int|\Illuminate\Http\JsonResponse
     */
    protected function requireAuthenticatedUserId()
    {
        $user = $this->getAuthenticatedUser();

        if (!$user) {
            return $this->unauthorizedResponse();
        }

        return $user->id;
    }

    /**
     * Получить права доступа пользователя в виде массива с учетом текущей компании
     *
     * @param \App\Models\User|null $user
     * @param int|null $companyId ID компании (если null, берется из заголовка X-Company-ID)
     * @return array
     */
    protected function getUserPermissions($user = null, ?int $companyId = null)
    {
        $user = $user ?? $this->getAuthenticatedUser();

        if (!$user) {
            return [];
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
        $permissions = $this->getUserPermissions($user);
        return in_array($permission, $permissions);
    }

    /**
     * Проверить, есть ли у пользователя право на действие с записью (с учетом _all/_own)
     *
     * @param string $resource Ресурс (например, 'users', 'orders')
     * @param string $action Действие (например, 'view', 'update', 'delete')
     * @param mixed $record Запись для проверки (должна иметь user_id)
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

        // Проверяем право на все записи
        if (in_array("{$resource}_{$action}_all", $permissions)) {
            return true;
        }

        // Проверяем право на свои записи
        if (in_array("{$resource}_{$action}_own", $permissions)) {
            if ($record) {
                // Для пользователей проверяем по id
                if ($resource === 'users' && method_exists($record, 'getKey')) {
                    return $record->getKey() === $user->id;
                }

                // Для касс проверяем через связь many-to-many
                if ($resource === 'cash_registers' && method_exists($record, 'hasUser')) {
                    return $record->hasUser($user->id);
                }

                // Для складов проверяем через связь many-to-many
                if ($resource === 'warehouses' && method_exists($record, 'users')) {
                    return $record->users()->where('user_id', $user->id)->exists();
                }

                // Для остальных проверяем по user_id
                $userId = $record->user_id ?? null;
                return $userId && $userId === $user->id;
            }
            // Если записи нет, считаем что это своя запись
            return true;
        }

        // Обратная совместимость: проверяем старое разрешение
        if (in_array("{$resource}_{$action}", $permissions)) {
            return true;
        }

        return false;
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
        $userPermissions = $this->getUserPermissions($user);

        foreach ($permissions as $permission) {
            if (in_array($permission, $userPermissions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Вернуть ответ с ошибкой
     *
     * @param int $status HTTP статус код
     * @param string|null $message Сообщение об ошибке
     * @param string $key Ключ для сообщения (по умолчанию 'error')
     * @return \Illuminate\Http\JsonResponse
     */
    protected function errorResponseByStatus(int $status, $message = null, string $key = 'error'): JsonResponse
    {
        $defaultMessages = [
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ];

        return response()->json([
            $key => $message ?? $defaultMessages[$status] ?? 'Error'
        ], $status);
    }

    /**
     * Вернуть ответ с ошибкой 401 (Unauthorized)
     *
     * @param string|null $message
     * @return \Illuminate\Http\JsonResponse
     */
    protected function unauthorizedResponse($message = null)
    {
        return $this->errorResponseByStatus(401, $message);
    }

    /**
     * Вернуть ответ с ошибкой 403 (Forbidden)
     *
     * @param string|null $message
     * @return \Illuminate\Http\JsonResponse
     */
    protected function forbiddenResponse($message = null)
    {
        return $this->errorResponseByStatus(403, $message);
    }

    /**
     * Вернуть ответ с ошибкой 404 (Not Found)
     *
     * @param string|null $message
     * @return \Illuminate\Http\JsonResponse
     */
    protected function notFoundResponse($message = null)
    {
        return $this->errorResponseByStatus(404, $message);
    }

    protected function successResponse($data = null, $message = null, $status = 200)
    {
        $response = [];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            if (is_array($data) && isset($data['message'])) {
                $response = array_merge($response, $data);
            } else {
                $response['data'] = $data;
            }
        }

        return response()->json($response, $status);
    }

    protected function paginatedResponse($items)
    {
        return response()->json([
            'items' => $items->items(),
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'total' => $items->total()
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
     * @param string $userIdField Поле с ID пользователя (по умолчанию 'user_id')
     * @param \App\Models\User|null $user Пользователь (по умолчанию текущий)
     * @return bool
     */
    protected function isOwnerOrAdmin(Model $model, string $userIdField = 'user_id', $user = null): bool
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
     * @param string $userIdField Поле с ID пользователя (по умолчанию 'user_id')
     * @param \App\Models\User|null $user Пользователь (по умолчанию текущий)
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function requireOwnerOrAdmin(Model $model, string $userIdField = 'user_id', $user = null): void
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
    protected function validationErrorResponse($validator)
    {
        return response()->json([
            'message' => 'Ошибка валидации',
            'errors' => $validator->errors()
        ], 422);
    }

    /**
     * Вернуть ответ с ошибкой сервера
     *
     * @param string|null $message
     * @param int $status
     * @return \Illuminate\Http\JsonResponse
     */
    protected function errorResponse($message = null, $status = 500)
    {
        return $this->errorResponseByStatus($status, $message);
    }

    /**
     * Проверить права доступа к кассе
     *
     * @param int|null $cashId ID кассы
     * @return \Illuminate\Http\JsonResponse|null Возвращает ответ с ошибкой, если нет прав, иначе null
     */
    protected function checkCashRegisterAccess(?int $cashId)
    {
        if ($cashId) {
            $cashRegister = \App\Models\CashRegister::find($cashId);
            if ($cashRegister && !$this->canPerformAction('cash_registers', 'view', $cashRegister)) {
                return $this->forbiddenResponse('У вас нет прав на эту кассу');
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
    protected function checkWarehouseAccess(?int $warehouseId)
    {
        if ($warehouseId) {
            $warehouse = \App\Models\Warehouse::find($warehouseId);
            if ($warehouse && !$this->canPerformAction('warehouses', 'view', $warehouse)) {
                return $this->forbiddenResponse('У вас нет прав на этот склад');
            }
        }
        return null;
    }
}

