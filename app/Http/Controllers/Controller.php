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
     * Получить права доступа пользователя в виде массива
     *
     * @param \App\Models\User|null $user
     * @return array
     */
    protected function getUserPermissions($user = null)
    {
        $user = $user ?? $this->getAuthenticatedUser();

        if (!$user) {
            return [];
        }

        return $user->permissions->pluck('name')->toArray();
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
     * Проверить права доступа к кассе
     *
     * @param int $cashId ID кассы
     * @param \App\Models\User|null $user Пользователь (по умолчанию текущий)
     * @return bool
     */
    protected function hasCashRegisterAccess(int $cashId, $user = null): bool
    {
        $user = $user ?? $this->requireAuthenticatedUser();

        // Проверяем различные варианты имен репозиториев
        $repositoryNames = ['itemsRepository', 'itemRepository', 'warehouseRepository', 'ordersRepository', 'transactions_repository'];

        foreach ($repositoryNames as $repoName) {
            if (property_exists($this, $repoName)) {
                $repository = $this->$repoName ?? null;
                if ($repository && is_object($repository) && method_exists($repository, 'userHasPermissionToCashRegister')) {
                    /** @phpstan-ignore-next-line */
                    return $repository->userHasPermissionToCashRegister($user->id, $cashId);
                }
            }
        }

        // Или используем прямую проверку через связь
        return $user->cashRegisters()->where('cash_registers.id', $cashId)->exists();
    }

    /**
     * Проверить права доступа к кассе или выбросить исключение
     *
     * @param int $cashId ID кассы
     * @param \App\Models\User|null $user Пользователь (по умолчанию текущий)
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function requireCashRegisterAccess(int $cashId, $user = null): void
    {
        if (!$this->hasCashRegisterAccess($cashId, $user)) {
            abort(403, 'У вас нет прав на эту кассу');
        }
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
}

