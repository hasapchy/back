<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Exports\GenericExport;
use App\Repositories\ClientsRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер для работы с клиентами
 */
class ClientController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param  ClientsRepository  $itemsRepository  Репозиторий клиентов
     */
    public function __construct(ClientsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список клиентов с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $params = $this->getClientListParams($request);
        $items = $this->itemsRepository->getItemsWithPagination(
            $params['per_page'],
            $params['search'],
            $params['include_inactive'],
            $params['page'],
            $params['status_filter'],
            $params['type_filter']
        );

        return $this->successResponse([
            'items' => ClientResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'next_page' => $items->nextPageUrl(),
                'prev_page' => $items->previousPageUrl(),
            ],
        ]);
    }

    /**
     * Экспорт клиентов в Excel (по фильтру или по выбранным id).
     *
     * @param  Request  $request
     * @return BinaryFileResponse
     */
    public function export(Request $request): BinaryFileResponse
    {
        $params = $this->getClientListParams($request);
        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            $ids = $ids ? [$ids] : [];
        }
        $ids = array_filter(array_map('intval', $ids));

        $items = $this->itemsRepository->getItemsForExport(
            $params['search'],
            $params['include_inactive'],
            $params['status_filter'],
            $params['type_filter'],
            $ids ?: null,
            10000
        );
        $headings = ['№', 'Имя', 'Тип', 'Статус', 'Телефоны', 'Email', 'Адрес', 'Примечание'];
        $rows = $items->map(function ($client) {
            $phones = $client->phones->pluck('phone')->implode(', ');
            $emails = $client->emails->pluck('email')->implode(', ');

            return [
                $client->id,
                trim($client->first_name . ' ' . $client->last_name),
                $client->client_type ?? '',
                $client->status ? 'Активен' : 'Неактивен',
                $phones,
                $emails,
                $client->address ?? '',
                $client->note ?? '',
            ];
        })->all();
        $filename = 'clients_' . date('Y-m-d_His') . '.xlsx';
        return Excel::download(new GenericExport($rows, $headings), $filename, \Maatwebsite\Excel\Excel::XLSX);
    }

    /**
     * Параметры списка клиентов из Request
     *
     * @param  Request  $request
     * @return array
     */
    protected function getClientListParams(Request $request): array
    {
        $typeFilter = $request->input('type_filter', []);
        if (! is_array($typeFilter)) {
            $typeFilter = $typeFilter !== null ? [$typeFilter] : [];
        }

        return [
            'per_page' => (int) $request->input('per_page', 10),
            'page' => (int) $request->input('page', 1),
            'search' => $request->input('search'),
            'include_inactive' => $request->boolean('include_inactive', false),
            'status_filter' => $request->input('status_filter'),
            'type_filter' => $typeFilter,
        ];
    }

    /**
     * Поиск клиентов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $search_request = $request->input('search_request');

        if (! $search_request || empty($search_request)) {
            return $this->successResponse([]);
        }

        $typeFilterInput = $request->input('type_filter');
        $typeFilter = [];
        if (is_array($typeFilterInput)) {
            $typeFilter = $typeFilterInput;
        } elseif (! is_null($typeFilterInput)) {
            $typeFilter = [$typeFilterInput];
        }

        $items = $this->itemsRepository->searchClient($search_request, $typeFilter);

        $resource = \App\Http\Resources\ClientSearchResource::collection($items);

        return $this->successResponse($resource->toArray($request));
    }

    /**
     * Получить клиента по ID
     *
     * @param  int  $id  ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $client = $this->itemsRepository->getItemById($id);

            if (! $client) {
                return $this->errorResponse('Client not found', 404);
            }

            $currentUser = $this->getAuthenticatedUser();
            $canViewOwnBalance = $currentUser && $this->hasPermission('settings_client_balance_view_own', $currentUser)
                && (int) $client->employee_id === (int) $currentUser->id;

            if (!$canViewOwnBalance && ! $this->canPerformAction('clients', 'view', $client)) {
                return $this->errorResponse('У вас нет прав на просмотр этого клиента', 403);
            }

            return (new ClientResource($client))->response();
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении клиента: '.$e->getMessage(), 500);
        }
    }

    /**
     * Получить историю баланса клиента
     *
     * @param  int  $id  ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBalanceHistory(Request $request, $id)
    {
        $user = $this->requireAuthenticatedUser();

        $client = $this->itemsRepository->getItemById($id);
        $canViewOwnBalance = $client && (int) $client->employee_id === (int) $user->id
            && $this->hasPermission('settings_client_balance_view_own', $user);
        $canViewAllBalance = $this->hasPermission('settings_client_balance_view', $user);

        if (!$canViewOwnBalance && !$canViewAllBalance) {
            return $this->errorResponse('Нет доступа к просмотру баланса клиента', 403);
        }

        $excludeDebt = $request->boolean('exclude_debt');
        $cashRegisterId = $request->input('cash_register_id') ? intval($request->input('cash_register_id')) : null;
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $source = $request->input('source');
        $isDebt = $request->input('is_debt');
        $isDebt = is_null($isDebt) ? null : filter_var($isDebt, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $balanceIdRaw = $request->input('balance_id');
        $balanceId = $balanceIdRaw ? intval($balanceIdRaw) : null;
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        $result = $this->itemsRepository->getBalanceHistory($id, $excludeDebt, $cashRegisterId, $dateFrom, $dateTo, $balanceId, $page, $perPage, $source, $isDebt);

        return $this->successResponse($result);
    }

    /**
     * Получить всех клиентов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        try {
            $typeFilterInput = $request->input('type_filter');
            $typeFilter = [];

            if (is_array($typeFilterInput)) {
                $typeFilter = $typeFilterInput;
            } elseif (! is_null($typeFilterInput)) {
                $typeFilter = [$typeFilterInput];
            }

            $forMutualSettlements = $request->input('for_mutual_settlements', false);
            $search = $request->input('search');
            $onlyWithBalance = $request->input('only_with_balance', false);
            $currencyId = $request->input('currency_id');
            $balanceDirection = $request->input('balance_direction');
            $balanceDirection = in_array($balanceDirection, ['positive', 'negative'], true) ? $balanceDirection : null;

            if ($forMutualSettlements) {
                $user = $this->requireAuthenticatedUser();
                $allowedTypes = $this->getAllowedMutualSettlementsClientTypes($user);

                if (empty($allowedTypes)) {
                    return $this->successResponse([]);
                }

                if (empty($typeFilter)) {
                    $typeFilter = $allowedTypes;
                } else {
                    $typeFilter = array_intersect($typeFilter, $allowedTypes);
                }
            }

            $items = $this->itemsRepository->getAllItems(
                $typeFilter,
                $forMutualSettlements,
                $search,
                (bool) $onlyWithBalance,
                $currencyId,
                $balanceDirection
            );

            return ClientResource::collection($items)->response();
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении всех клиентов: '.$e->getMessage(), 500);
        }
    }

    /**
     * Создать нового клиента
     *
     * @param  Request  $request
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
            $validatedData['creator_id'] = $this->getAuthenticatedUserIdOrFail();
            $client = $this->itemsRepository->createItem($validatedData);

            DB::commit();

            CacheService::invalidateClientsCache();
            CacheService::invalidateOrdersCache();
            CacheService::invalidateSalesCache();
            CacheService::invalidateTransactionsCache();

            $client->load('phones', 'emails', 'employee');

            return (new ClientResource($client))->additional([
                'message' => 'Client created successfully',
            ])->response();
        } catch (\Throwable $e) {
            DB::rollBack();

            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return $this->errorResponse('Email уже используется другим клиентом', 422);
            }

            return $this->errorResponse('Ошибка при создании клиента: '.$e->getMessage(), 500);
        }
    }

    /**
     * Обновить клиента
     *
     * @param  Request  $request
     * @param  int  $id  ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateClientRequest $request, $id)
    {
        $validatedData = $this->normalizeNullableFields($request->validated());

        $existingClient = Client::findOrFail($id);

        if (! $this->canPerformAction('clients', 'update', $existingClient)) {
            return $this->errorResponse('У вас нет прав на редактирование этого клиента', 403);
        }

        $employeeCheck = $this->checkEmployeeIdDuplicate($validatedData['employee_id'] ?? null, $id);
        if ($employeeCheck) {
            return $employeeCheck;
        }

        $phoneCheck = $this->checkPhoneDuplicates($validatedData['phones'] ?? [], $id);
        if ($phoneCheck) {
            return $phoneCheck;
        }

        try {
            $client = $this->itemsRepository->updateItem($id, $validatedData);

            CacheService::invalidateClientsCache();
            CacheService::invalidateOrdersCache();
            CacheService::invalidateSalesCache();
            CacheService::invalidateTransactionsCache();

            return (new ClientResource($client))->additional([
                'message' => 'Client updated successfully',
            ])->response();
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return $this->errorResponse('Email уже используется другим клиентом', 422);
            }

            return $this->errorResponse('Ошибка при обновлении клиента: '.$e->getMessage(), 500);
        }
    }

    protected function normalizeNullableFields(array $data): array
    {
        $fields = [
            'last_name',
            'patronymic',
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
     * @param  int  $id  ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $client = Client::find($id);

            if (! $client) {
                return $this->errorResponse('Клиент не найден', 404);
            }

            if (! $this->canPerformAction('clients', 'delete', $client)) {
                return $this->errorResponse('У вас нет прав на удаление этого клиента', 403);
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

                return $this->successResponse(null, 'Клиент успешно удалён');
            } else {
                return $this->errorResponse('Клиент не найден', 404);
            }
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при удалении клиента: '.$e->getMessage(), 500);
        }
    }

    /**
     * Проверить дублирование employee_id
     *
     * @param  int|null  $employeeId  ID сотрудника
     * @param  int|null  $excludeId  ID клиента для исключения из проверки
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
     * @param  array  $phones  Массив телефонов
     * @param  int|null  $excludeClientId  ID клиента для исключения из проверки
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
