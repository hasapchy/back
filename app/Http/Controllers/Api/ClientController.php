<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Repositories\ClientsRepository;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use App\Services\CacheService;

class ClientController extends Controller
{
    protected $itemsRepository;

    public function __construct(ClientsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }


    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $includeInactive = $request->input('include_inactive', false);
        $statusFilter = $request->input('status_filter');
        $typeFilter = $request->input('type_filter');
        $items = $this->itemsRepository->getItemsWithPagination($perPage, $search, $includeInactive, $page, $statusFilter, $typeFilter);

        return $this->paginatedResponse($items);
    }

    public function search(Request $request)
    {
        $search_request = $request->input('search_request');

        if (!$search_request || empty($search_request)) {
            $items = [];
        } else {
            $items = $this->itemsRepository->searchClient($search_request);
        }

        return response()->json($items);
    }

        public function show($id)
    {
        try {
            if (method_exists($this->itemsRepository, 'invalidateClientBalanceCache')) {
                $this->itemsRepository->invalidateClientBalanceCache($id);
            }

            $client = $this->itemsRepository->getItemById($id);

            if (!$client) {
                return $this->notFoundResponse('Client not found');
            }

            // Проверяем права с учетом _all/_own
            if (!$this->canPerformAction('clients', 'view', $client)) {
                return $this->forbiddenResponse('У вас нет прав на просмотр этого клиента');
            }

            return response()->json(['item' => $client]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении клиента: ' . $e->getMessage(), 500);
        }
    }

    public function getBalanceHistory($id)
    {
        $user = $this->requireAuthenticatedUser();

        if (!$this->hasPermission('settings_client_balance_view', $user)) {
            return $this->forbiddenResponse('Нет доступа к просмотру баланса клиента');
        }

        try {
            $history = $this->itemsRepository->getBalanceHistory($id);

            return response()->json(['history' => $history]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении истории баланса: ' . $e->getMessage(), 500);
        }
    }

    public function all()
    {
        try {
            $items = $this->itemsRepository->getAllItems();
            return response()->json($items);
        } catch (\Throwable $e) {
            return $this->errorResponse('Ошибка при получении всех клиентов: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'first_name'       => 'required|string',
            'is_conflict'      => 'sometimes|nullable|boolean',
            'is_supplier'      => 'sometimes|nullable|boolean',
            'last_name'        => 'nullable|string',
            'contact_person'   => 'nullable|string',
            'client_type'      => 'required|string|in:company,individual,employee,investor',
            'employee_id'      => 'nullable|exists:users,id',
            'address'          => 'nullable|string',
            'phones'           => 'required|array',
            'phones.*'         => 'string|distinct|min:6',
            'emails'           => 'sometimes|nullable',
            'emails.*'         => 'nullable|email|distinct',
            'note'             => 'nullable|string',
            'status'           => 'boolean',
            'discount'         => 'nullable|numeric|min:0',
            'discount_type'    => 'nullable|in:fixed,percent',
        ]);

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

            return response()->json(['item' => $client->load('phones', 'emails', 'employee'), 'message' => 'Client created successfully']);
        } catch (\Throwable $e) {
            DB::rollBack();

            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return $this->errorResponse('Email уже используется другим клиентом', 422);
            }

            return $this->errorResponse('Ошибка при создании клиента: ' . $e->getMessage(), 500);
        }
    }
    public function update(Request $request, $id)
    {
        $validatedData = $request->validate([
            'first_name'       => 'required|string',
            'is_conflict'      => 'sometimes|nullable|boolean',
            'is_supplier'      => 'sometimes|nullable|boolean',
            'last_name'        => 'nullable|string',
            'contact_person'   => 'nullable|string',
            'client_type'      => 'required|string|in:company,individual,employee,investor',
            'employee_id'      => 'nullable|exists:users,id',
            'address'          => 'nullable|string',
            'phones'           => 'required|array',
            'phones.*'         => 'string|distinct|min:6',
            'emails'           => 'sometimes|nullable',
            'emails.*'         => 'nullable|email|distinct',
            'note'             => 'nullable|string',
            'status'           => 'boolean',
            'discount'         => 'nullable|numeric|min:0',
            'discount_type'    => 'nullable|in:fixed,percent',
        ]);

        try {
            $existingClient = Client::find($id);
            if (!$existingClient) {
                return $this->notFoundResponse('Клиент не найден');
            }

            // Проверяем права с учетом _all/_own
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

            return response()->json(['client' => $client, 'message' => 'Client updated successfully']);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return $this->errorResponse('Email уже используется другим клиентом', 422);
            }

            return $this->errorResponse('Ошибка при обновлении клиента: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $client = Client::find($id);
            if (!$client) {
                return $this->notFoundResponse('Клиент не найден');
            }

            // Проверяем права с учетом _all/_own
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
