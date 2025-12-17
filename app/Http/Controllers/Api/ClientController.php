<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientCollection;
use Illuminate\Http\Request;
use App\Repositories\ClientsRepository;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ClientResource;
use App\Services\CacheService;

/**
 * Контроллер для работы с клиентами
 */
class ClientController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param ClientsRepository $itemsRepository Репозиторий клиентов
     */
    public function __construct(ClientsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список клиентов с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $includeInactive = $request->input('include_inactive', false);
        $statusFilter = $request->input('status_filter');
        $typeFilter = $request->input('type_filter');
        $items = $this->itemsRepository->getItemsWithPagination($perPage, $search, $includeInactive, $page, $statusFilter, $typeFilter);

        return new ClientCollection($items);
    }

    /**
     * Поиск клиентов
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $search_request = $request->input('search_request');

        if (!$search_request || empty($search_request)) {
            return response()->json([]);
        }

        $items = $this->itemsRepository->searchClient($search_request);

        $resource = \App\Http\Resources\ClientSearchResource::collection($items);

        return response()->json($resource->toArray($request));
    }

    /**
     * Получить клиента по ID
     *
     * @param int $id ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $client = $this->itemsRepository->getItemById($id);

            if (!$client) {
                return $this->notFoundResponse('Client not found');
            }

            if (!$this->canPerformAction('clients', 'view', $client)) {
                return $this->forbiddenResponse('У вас нет прав на просмотр этого клиента');
            }

            return new ClientResource($client);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении клиента: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получить историю баланса клиента
     *
     * @param Request $request
     * @param int $id ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBalanceHistory(Request $request, $id)
    {
        $user = $this->requireAuthenticatedUser();

        if (!$this->hasPermission('settings_client_balance_view', $user)) {
            return $this->forbiddenResponse('Нет доступа к просмотру баланса клиента');
        }

        try {
            $excludeDebt = $request->input('exclude_debt', null);
            if ($excludeDebt !== null) {
                $excludeDebt = filter_var($excludeDebt, FILTER_VALIDATE_BOOLEAN);
            }

            $history = $this->itemsRepository->getBalanceHistory($id, $excludeDebt);

            return response()->json(['history' => $history]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении истории баланса: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получить всех клиентов
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        try {
            $typeFilterInput = $request->input('type_filter');
            $typeFilter = [];

            if (is_array($typeFilterInput)) {
                $typeFilter = $typeFilterInput;
            } elseif (!is_null($typeFilterInput)) {
                $typeFilter = [$typeFilterInput];
            }

            $forMutualSettlements = $request->input('for_mutual_settlements', false);

            if ($forMutualSettlements) {
                $user = $this->requireAuthenticatedUser();
                $allowedTypes = $this->getAllowedMutualSettlementsClientTypes($user);

                if (empty($allowedTypes)) {
                    return response()->json([]);
                }

                if (empty($typeFilter)) {
                    $typeFilter = $allowedTypes;
                } else {
                    $typeFilter = array_intersect($typeFilter, $allowedTypes);
                }
            }

            $items = $this->itemsRepository->getAllItems($typeFilter, $forMutualSettlements);
            return ClientResource::collection($items);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении всех клиентов: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Создать нового клиента
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreClientRequest $request)
    {
        $validatedData = $this->normalizeNullableFields($request->validated());

        $employeeCheck = $this->checkEmployeeIdDuplicate($validatedData['employee_id'] ?? null);
        if ($employeeCheck) {
            return $employeeCheck;
        }

        $phoneCheck = $this->checkPhoneDuplicates($validatedData['phones'] ?? [], null);
        if ($phoneCheck) {
            return $phoneCheck;
        }

        DB::beginTransaction();
        try {
            $validatedData['user_id'] = $this->getAuthenticatedUserIdOrFail();
            $client = $this->itemsRepository->createItem($validatedData);

            DB::commit();

            CacheService::invalidateClientsCache();
            CacheService::invalidateOrdersCache();
            CacheService::invalidateSalesCache();
            CacheService::invalidateTransactionsCache();

            $client->load('phones', 'emails', 'employee');
            return (new ClientResource($client))->additional([
                'message' => 'Client created successfully'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return $this->errorResponse('Email уже используется другим клиентом', 422);
            }

            return $this->errorResponse('Ошибка при создании клиента: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Обновить клиента
     *
     * @param Request $request
     * @param int $id ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateClientRequest $request, $id)
    {
        $validatedData = $this->normalizeNullableFields($request->validated());

        try {
            $existingClient = Client::find($id);
            if (!$existingClient) {
                return $this->notFoundResponse('Клиент не найден');
            }

            if (!$this->canPerformAction('clients', 'update', $existingClient)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого клиента');
            }

            $employeeCheck = $this->checkEmployeeIdDuplicate($validatedData['employee_id'] ?? null, $id);
            if ($employeeCheck) {
                return $employeeCheck;
            }

            $phoneCheck = $this->checkPhoneDuplicates($validatedData['phones'] ?? [], $id);
            if ($phoneCheck) {
                return $phoneCheck;
            }

            $client = $this->itemsRepository->updateItem($id, $validatedData);

            CacheService::invalidateClientsCache();
            CacheService::invalidateOrdersCache();
            CacheService::invalidateSalesCache();
            CacheService::invalidateTransactionsCache();

            return (new ClientResource($client))->additional([
                'message' => 'Client updated successfully'
            ]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return $this->errorResponse('Email уже используется другим клиентом', 422);
            }

            return $this->errorResponse('Ошибка при обновлении клиента: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @param array $data
     * @return array
     */
    protected function normalizeNullableFields(array $data): array
    {
        $fields = [
            'last_name',
            'patronymic',
            'contact_person',
            'position',
            'address',
            'note',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /**
     * Удалить клиента
     *
     * @param int $id ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $client = Client::find($id);
            if (!$client) {
                return $this->notFoundResponse('Клиент не найден');
            }

            if (!$this->canPerformAction('clients', 'delete', $client)) {
                return $this->forbiddenResponse('У вас нет прав на удаление этого клиента');
            }

            $hasTransactions = DB::table('transactions')->where('client_id', $id)->exists();

            if ($hasTransactions) {
                return $this->errorResponse('Нельзя удалить клиента: найдены связанные транзакции.', 422);
            }

            $hasOrders = DB::table('orders')->where('client_id', $id)->exists();

            if ($hasOrders) {
                return $this->errorResponse('Нельзя удалить клиента: найдены связанные заказы.', 422);
            }

            $balance = DB::table('clients')->where('id', $id)->value('balance');

            if ($balance > 0 || $balance < 0) {
                return $this->errorResponse('Нельзя удалить клиента с ненулевым балансом.', 422);
            }

            $deleted = $this->itemsRepository->deleteItem($id);

            if ($deleted) {
                CacheService::invalidateClientsCache();
                CacheService::invalidateOrdersCache();
                CacheService::invalidateSalesCache();
                CacheService::invalidateTransactionsCache();

                return response()->json(['message' => 'Клиент успешно удалён']);
            } else {
                return $this->notFoundResponse('Клиент не найден');
            }
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при удалении клиента: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Проверить дублирование employee_id
     *
     * @param int|null $employeeId ID сотрудника
     * @param int|null $excludeId ID клиента для исключения из проверки
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function checkEmployeeIdDuplicate(?int $employeeId, ?int $excludeId = null)
    {
        if (empty($employeeId)) {
            return null;
        }

        $companyId = $this->getCurrentCompanyId();
        $query = Client::where('employee_id', $employeeId);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($companyId) {
            $query->where('company_id', $companyId);
        } else {
            $query->whereNull('company_id');
        }

        if ($query->exists()) {
            return $this->errorResponse('Этот пользователь уже привязан к другому клиенту', 422);
        }

        return null;
    }

    /**
     * Проверить дублирование телефонов
     *
     * @param array $phones Массив телефонов
     * @param int|null $excludeClientId ID клиента для исключения из проверки
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function checkPhoneDuplicates(array $phones, ?int $excludeClientId = null)
    {
        if (empty($phones)) {
            return null;
        }

        $companyId = $this->getCurrentCompanyId();
        foreach ($phones as $phone) {
            $query = DB::table('clients_phones')
                ->join('clients', 'clients_phones.client_id', '=', 'clients.id')
                ->where('clients_phones.phone', $phone);

            if ($excludeClientId) {
                $query->where('clients_phones.client_id', '!=', $excludeClientId);
            }

            if ($companyId) {
                $query->where('clients.company_id', $companyId);
            } else {
                $query->whereNull('clients.company_id');
            }

            if ($query->exists()) {
                return $this->errorResponse("Телефон {$phone} уже используется другим клиентом в этой компании", 422);
            }
        }

        return null;
    }
}
