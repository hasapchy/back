<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use Illuminate\Http\Request;
use App\Repositories\ClientsRepository;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use App\Services\CacheService;

/**
 * Контроллер для работы с клиентами
 */
class ClientController extends Controller
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

        return ClientResource::collection($items)->response();
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
            $items = [];
        } else {
            $items = $this->itemsRepository->searchClient($search_request);
        }

        return ClientResource::collection($items)->response();
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

            return $this->dataResponse(new ClientResource($client));
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении клиента: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получить историю баланса клиента
     *
     * @param int $id ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBalanceHistory($id)
    {
        $user = $this->requireAuthenticatedUser();

        if (!$this->hasPermission('settings_client_balance_view', $user)) {
            return $this->forbiddenResponse('Нет доступа к просмотру баланса клиента');
        }

        try {
            $history = $this->itemsRepository->getBalanceHistory($id);

            return $this->dataResponse(['history' => $history]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении истории баланса: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получить всех клиентов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all()
    {
        try {
            $items = $this->itemsRepository->getAllItems();
            return ClientResource::collection($items)->response();
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении всех клиентов: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Создать нового клиента
     *
     * @param StoreClientRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreClientRequest $request)
    {
        $validatedData = $request->validated();

        DB::beginTransaction();
        try {
            $validatedData['user_id'] = $this->getAuthenticatedUserIdOrFail();
            $client = $this->itemsRepository->createItem($validatedData);

            DB::commit();

            CacheService::invalidateClientsCache();
            CacheService::invalidateOrdersCache();
            CacheService::invalidateSalesCache();
            CacheService::invalidateTransactionsCache();

            $client = $client->load('phones', 'emails', 'employee', 'user');
            return $this->dataResponse(new ClientResource($client), 'Client created successfully');
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
     * @param UpdateClientRequest $request
     * @param int $id ID клиента
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateClientRequest $request, $id)
    {
        try {
            $existingClient = Client::find($id);
            if (!$existingClient) {
                return $this->notFoundResponse('Клиент не найден');
            }

            if (!$this->canPerformAction('clients', 'update', $existingClient)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого клиента');
            }

            $validatedData = $request->validated();

            $client = $this->itemsRepository->updateItem($id, $validatedData);

            CacheService::invalidateClientsCache();
            CacheService::invalidateOrdersCache();
            CacheService::invalidateSalesCache();
            CacheService::invalidateTransactionsCache();

            return $this->dataResponse(new ClientResource($client), 'Client updated successfully');
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return $this->errorResponse('Email уже используется другим клиентом', 422);
            }

            return $this->errorResponse('Ошибка при обновлении клиента: ' . $e->getMessage(), 500);
        }
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
