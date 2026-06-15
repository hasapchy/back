<?php

namespace App\Http\Controllers\Api;

use App\Batch\BatchEntityActions;
use App\Exports\GenericExport;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ClientSearchResource;
use App\Models\Client;
use App\Repositories\ClientsRepository;
use App\Services\CacheService;
use App\Services\InAppNotifications\InAppNotificationDispatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Контроллер для работы с клиентами
 */
/**
 * @group Клиенты
 */
class ClientController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param  ClientsRepository  $itemsRepository  Репозиторий клиентов
     */
    public function __construct(
        ClientsRepository $itemsRepository,
        private readonly InAppNotificationDispatcher $inAppNotificationDispatcher,
        private readonly BatchEntityActions $batchEntityActions,
    ) {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Список клиентов
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Client::class);

        $params = $this->getClientListParams($request);
        $items = $this->itemsRepository->getItemsWithPagination(
            $params['per_page'],
            $params['search'],
            $params['include_inactive'],
            $params['page'],
            $params['status_filter'],
            $params['type_filter']
        );

        $typeCounts = $this->itemsRepository->getTypeCountsForFilters(
            $params['search'],
            $params['include_inactive'],
            $params['status_filter']
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
                'type_counts' => $typeCounts['by_type'],
                'suppliers_count' => $typeCounts['suppliers'],
                'total_unfiltered_by_type' => $typeCounts['total'],
            ],
        ]);
    }

    /**
     * Экспорт клиентов в Excel (по фильтру или по выбранным id).
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
                trim($client->first_name.' '.$client->last_name),
                $client->client_type ?? '',
                $client->status ? 'Активен' : 'Неактивен',
                $phones,
                $emails,
                $client->address ?? '',
                $client->note ?? '',
            ];
        })->all();
        $filename = 'clients_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new GenericExport($rows, $headings), $filename, \Maatwebsite\Excel\Excel::XLSX);
    }

    /**
     * Параметры списка клиентов из Request
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
     * @return JsonResponse
     */
    public function search(Request $request)
    {
        $this->authorize('viewAny', Client::class);

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

        $resource = ClientSearchResource::collection($items);

        return $this->successResponse($resource->toArray($request));
    }

    /**
     * Получить клиента по ID
     *
     * @param  int  $id  ID клиента
     * @return JsonResponse
     */
    public function show($id)
    {
        try {
            $client = $this->itemsRepository->getItemById($id);

            if (! $client) {
                return $this->errorResponse(__('api.clients.not_found'), 404);
            }

            $currentUser = $this->getAuthenticatedUser();
            $canViewOwnBalance = $currentUser && $currentUser->can('settings_client_balance_view_own')
                && (int) $client->employee_id === (int) $currentUser->id;

            if (! $canViewOwnBalance && (! $currentUser || ! $currentUser->can('view', $client))) {
                return $this->errorResponse(__('api.clients.view_forbidden'), 403);
            }

            return (new ClientResource($client))->response();
        } catch (\Throwable $e) {
            return $this->errorResponse(__('api.clients.fetch_failed', ['message' => $e->getMessage()]), 500);
        }
    }

    /**
     * Получить историю баланса клиента
     *
     * @param  int  $id  ID клиента
     * @return JsonResponse
     */
    public function getBalanceHistory(Request $request, $id)
    {
        $user = $this->requireAuthenticatedUser();

        $client = $this->itemsRepository->getItemById($id);
        $canViewOwnBalance = $client && (int) $client->employee_id === (int) $user->id
            && $user->can('settings_client_balance_view_own');
        $canViewAllBalance = $user->can('settings_client_balance_view');

        if (! $canViewOwnBalance && ! $canViewAllBalance) {
            return $this->errorResponse(__('api.clients.balance_view_forbidden'), 403);
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
        $search = $request->input('search');
        $transactionType = $request->input('transaction_type');

        $result = $this->itemsRepository->getBalanceHistory(
            $id,
            $excludeDebt,
            $cashRegisterId,
            $dateFrom,
            $dateTo,
            $balanceId,
            $page,
            $perPage,
            $source,
            $isDebt,
            $search,
            $transactionType
        );

        return $this->successResponse($result);
    }

    /**
     * Сводка взаиморасчётов по всем валютам (без конвертации).
     *
     * @return JsonResponse
     */
    public function settlementsSummary(): JsonResponse
    {
        try {
            $items = $this->itemsRepository->getSettlementsSummaryByCurrency();

            return $this->successResponse(['by_currency' => $items]);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                __('api.clients.settlements_summary_failed', ['message' => $e->getMessage()]),
                500
            );
        }
    }

    /**
     * Получить всех клиентов
     *
     * @return JsonResponse
     */
    public function all(Request $request)
    {
        $this->authorize('viewAny', Client::class);

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

            $balanceTypeFilterInput = $request->input('balance_type_filter');
            $balanceTypeFilter = null;
            if (is_array($balanceTypeFilterInput)) {
                $balanceTypeFilter = $balanceTypeFilterInput;
            } elseif ($balanceTypeFilterInput !== null && $balanceTypeFilterInput !== '') {
                $balanceTypeFilter = [$balanceTypeFilterInput];
            }

            $items = $this->itemsRepository->getAllItems(
                $typeFilter,
                $forMutualSettlements,
                $search,
                (bool) $onlyWithBalance,
                $currencyId,
                $balanceDirection,
                $balanceTypeFilter
            );

            return ClientResource::collection($items)->response();
        } catch (\Throwable $e) {
            return $this->errorResponse(__('api.clients.all_fetch_failed', ['message' => $e->getMessage()]), 500);
        }
    }

    /**
     * Создать клиента
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function store(StoreClientRequest $request)
    {
        $validatedData = $this->normalizeNullableFields($request->validated());

        if (($validatedData['client_type'] ?? null) === 'employee') {
            return $this->errorResponse(__('api.clients.employee_auto_created'), 422);
        }

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

            $companyId = (int) $this->getCurrentCompanyId();
            if ($companyId >= 1) {
                $this->inAppNotificationDispatcher->dispatch(
                    $companyId,
                    'clients_new',
                    $this->getAuthenticatedUserIdOrFail(),
                    __('api.clients.new_client_notification_title', ['id' => $client->id]),
                    null,
                    ['route' => '/clients/'.$client->id, 'client_id' => $client->id]
                );
            }

            return (new ClientResource($client))->additional([
                'message' => __('api.clients.created_success'),
            ])->response();
        } catch (\DomainException $e) {
            DB::rollBack();

            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return $this->errorResponse(__('api.clients.email_already_used'), 422);
            }

            return $this->errorResponse(__('api.clients.create_failed', ['message' => $e->getMessage()]), 500);
        }
    }

    /**
     * Обновить клиента
     *
     * @param  Request  $request
     * @param  int  $id  ID клиента
     * @return JsonResponse
     */
    public function update(UpdateClientRequest $request, $id)
    {
        $validatedData = $this->normalizeNullableFields($request->validated());

        $existingClient = Client::findOrFail($id);

        if ($existingClient->client_type === 'employee') {
            return $this->errorResponse(__('api.clients.employee_edit_only_via_user'), 422);
        }

        $this->authorize('update', $existingClient);

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
                'message' => __('api.clients.updated_success'),
            ])->response();
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return $this->errorResponse(__('api.clients.email_already_used'), 422);
            }

            return $this->errorResponse(__('api.clients.update_failed', ['message' => $e->getMessage()]), 500);
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
     * @return JsonResponse
     */
    public function destroy($id)
    {
        try {
            $this->batchEntityActions->deleteClient($this->requireAuthenticatedUser(), (int) $id);

            return $this->successResponse(null, __('api.clients.deleted_success'));
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (NotFoundHttpException $e) {
            return $this->errorResponse($e->getMessage() ?: __('api.clients.not_found'), 404);
        } catch (UnprocessableEntityHttpException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->errorResponse(__('api.clients.delete_failed', ['message' => $e->getMessage()]), 500);
        }
    }

    /**
     * Проверить дублирование employee_id
     *
     * @param  int|null  $employeeId  ID сотрудника
     * @param  int|null  $excludeId  ID клиента для исключения из проверки
     * @return JsonResponse|null
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
            return $this->errorResponse(__('api.clients.employee_already_attached'), 422);
        }

        return null;
    }

    /**
     * Проверить дублирование телефонов
     *
     * @param  array  $phones  Массив телефонов
     * @param  int|null  $excludeClientId  ID клиента для исключения из проверки
     * @return JsonResponse|null
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
                return $this->errorResponse(__('api.clients.phone_already_used', ['phone' => $phone]), 422);
            }
        }

        return null;
    }
}
