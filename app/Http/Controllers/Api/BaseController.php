<?php

namespace App\Http\Controllers\Api;

use App\Models\CashRegister;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CompanyScopedPermissions;
use App\Support\ResolvedCompany;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseRoutingController;
use Illuminate\Support\Facades\DB;

class BaseController extends BaseRoutingController
{
    use AuthorizesRequests, ValidatesRequests;

    public const BATCH_IDS_MAX = 50;

    protected function getCurrentCompanyId(): ?int
    {
        return ResolvedCompany::fromRequest(request());
    }

    /**
     * Получить авторизованного пользователя API
     *
     * @return User|null
     */
    protected function getAuthenticatedUser()
    {
        return auth('api')->user();
    }

    /**
     * Получить авторизованного пользователя или выбросить исключение
     *
     * @return User
     *
     * @throws HttpResponseException
     */
    protected function requireAuthenticatedUser()
    {
        $user = $this->getAuthenticatedUser();

        if (! $user) {
            abort(401, 'Unauthorized');
        }

        return $user;
    }

    /**
     * Получить ID авторизованного пользователя или выбросить исключение
     *
     * @throws HttpResponseException
     */
    protected function getAuthenticatedUserIdOrFail(): int
    {
        return $this->requireAuthenticatedUser()->id;
    }

    /**
     * Получить права доступа пользователя в виде массива с учетом текущей компании
     *
     * @param  User|null  $user
     * @param  int|null  $companyId  ID компании (если null, из контекста запроса)
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
     * @param  string  $clientType  Тип клиента (individual, company, employee, investor)
     * @param  User|null  $user  Пользователь
     * @return bool
     */
    protected function canViewMutualSettlementsByClientType($clientType, $user = null)
    {
        $user = $user ?? $this->getAuthenticatedUser();
        if (! $user) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        $names = CompanyScopedPermissions::names($user);
        $config = config('permissions.resources.mutual_settlements');
        $permissionName = $config['custom_permissions']["view_{$clientType}"] ?? "mutual_settlements_view_{$clientType}";

        return in_array($permissionName, $names, true);
    }

    /**
     * Получить доступные типы клиентов для просмотра взаиморасчетов
     *
     * @param  User|null  $user  Пользователь
     * @return array Массив типов клиентов, к которым есть доступ
     */
    protected function getAllowedMutualSettlementsClientTypes($user = null)
    {
        $user = $user ?? $this->getAuthenticatedUser();
        if (! $user) {
            return [];
        }

        if ($user->is_admin) {
            return ['individual', 'company', 'employee', 'investor'];
        }

        $names = CompanyScopedPermissions::names($user);
        $allowedTypes = [];
        $config = config('permissions.resources.mutual_settlements');

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
     * @param  User|null  $user
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
     * @param  mixed  $data
     */
    protected function successResponse($data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return $this->messageResponse($status, $message, $data);
    }

    /**
     * @param  LengthAwarePaginator  $items
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
     * @return mixed
     *
     * @throws \Throwable
     */
    protected function transaction(callable $callback)
    {
        return DB::transaction($callback);
    }

    /**
     * Вернуть ответ с ошибкой валидации
     *
     * @param  Validator  $validator
     */
    protected function validationErrorResponse($validator): JsonResponse
    {
        return $this->messageResponse(422, 'Ошибка валидации', null, 'message', $validator->errors()->toArray());
    }

    /**
     * Вернуть ответ с ошибкой сервера
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
     * @param  mixed  $data
     * @param  array<string, mixed>|null  $errors
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
     * @param  int|null  $cashId  ID кассы
     * @return JsonResponse|null Возвращает ответ с ошибкой, если нет прав, иначе null
     */
    protected function checkCashRegisterAccess(?int $cashId): ?JsonResponse
    {
        if ($cashId) {
            $cashRegister = CashRegister::query()->find($cashId);
            if (! $cashRegister) {
                return $this->errorResponse(__('warehouse_receipt.cash_register_not_found'), 422);
            }
            $user = $this->getAuthenticatedUser();
            if ($user && ! $user->can('view', $cashRegister)) {
                return $this->errorResponse(__('warehouse_receipt.cash_register_forbidden'), 403);
            }
        }

        return null;
    }

    /**
     * Проверить права доступа к складу
     *
     * @param  int|null  $warehouseId  ID склада
     * @return JsonResponse|null Возвращает ответ с ошибкой, если нет прав, иначе null
     */
    protected function checkWarehouseAccess(?int $warehouseId): ?JsonResponse
    {
        if ($warehouseId) {
            $warehouse = Warehouse::find($warehouseId);
            $user = $this->getAuthenticatedUser();
            if ($warehouse && $user && ! $user->can('view', $warehouse)) {
                return $this->errorResponse('У вас нет прав на этот склад', 403);
            }
        }

        return null;
    }

}
