<?php

namespace App\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseRoutingController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Support\CompanyScopedPermissions;
use App\Support\ResolvedCompany;

class BaseController extends BaseRoutingController
{
    use AuthorizesRequests, ValidatesRequests;

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

        if (! $user) {
            return [];
        }

        if ($companyId !== null) {
            return CompanyScopedPermissions::namesForCompany($user, $companyId);
        }

        return CompanyScopedPermissions::names($user);
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

        $names = CompanyScopedPermissions::names($user);
        $config = config("permissions.resources.mutual_settlements");
        $permissionName = $config['custom_permissions']["view_{$clientType}"] ?? "mutual_settlements_view_{$clientType}";

        return in_array($permissionName, $names, true);
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

        $names = CompanyScopedPermissions::names($user);
        $allowedTypes = [];
        $config = config("permissions.resources.mutual_settlements");

        if (isset($config['custom_permissions'])) {
            foreach ($config['custom_permissions'] as $key => $permissionName) {
                if (in_array($permissionName, $names, true)) {
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

        if (! $user) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return CompanyScopedPermissions::userHasAny($user, $permissions);
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
            $user = $this->getAuthenticatedUser();
            if ($cashRegister && $user && ! $user->can('view', $cashRegister)) {
                return $this->errorResponse('У вас нет прав на эту кассу', 403);
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
            $user = $this->getAuthenticatedUser();
            if ($warehouse && $user && ! $user->can('view', $warehouse)) {
                return $this->errorResponse('У вас нет прав на этот склад', 403);
            }
        }

        return null;
    }
}

