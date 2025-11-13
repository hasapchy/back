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
            $items = $this->itemsRepository->getItemsWithPagination(1000, null, false, 1);
            return response()->json($items->items());
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

        if (!empty($validatedData['employee_id'])) {
            $companyId = $this->getCurrentCompanyId();
            $existingClient = Client::where('employee_id', $validatedData['employee_id'])
                ;

            if ($companyId) {
                $existingClient->where('company_id', $companyId);
            } else {
                $existingClient->whereNull('company_id');
            }

            if ($existingClient->exists()) {
                return $this->errorResponse('Этот пользователь уже привязан к другому клиенту', 422);
            }
        }

        $companyId = $this->getCurrentCompanyId();
        if (!empty($validatedData['phones'])) {
            foreach ($validatedData['phones'] as $phone) {
                $existingPhone = DB::table('clients_phones')
                    ->join('clients', 'clients_phones.client_id', '=', 'clients.id')
                    ->where('clients_phones.phone', $phone);

                if ($companyId) {
                    $existingPhone->where('clients.company_id', $companyId);
                } else {
                    $existingPhone->whereNull('clients.company_id');
                }

                if ($existingPhone->exists()) {
                    return $this->errorResponse("Телефон {$phone} уже используется другим клиентом в этой компании", 422);
                }
            }
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
        $user = $this->requireAuthenticatedUser();

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

            if (!$user->is_admin && $existingClient->user_id != $user->id) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого клиента');
            }

            if (!empty($validatedData['employee_id'])) {
                $companyId = $this->getCurrentCompanyId();
                $duplicateClient = Client::where('employee_id', $validatedData['employee_id'])
                    ->where('id', '!=', $id);

                if ($companyId) {
                    $duplicateClient->where('company_id', $companyId);
                } else {
                    $duplicateClient->whereNull('company_id');
                }

                if ($duplicateClient->exists()) {
                    return $this->errorResponse('Этот пользователь уже привязан к другому клиенту', 422);
                }
            }

            $companyId = $this->getCurrentCompanyId();
            if (!empty($validatedData['phones'])) {
                foreach ($validatedData['phones'] as $phone) {
                    $existingPhone = DB::table('clients_phones')
                        ->join('clients', 'clients_phones.client_id', '=', 'clients.id')
                        ->where('clients_phones.phone', $phone)
                        ->where('clients_phones.client_id', '!=', $id);

                    if ($companyId) {
                        $existingPhone->where('clients.company_id', $companyId);
                    } else {
                        $existingPhone->whereNull('clients.company_id');
                    }

                    if ($existingPhone->exists()) {
                        return $this->errorResponse("Телефон {$phone} уже используется другим клиентом в этой компании", 422);
                    }
                }
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
            $user = $this->requireAuthenticatedUser();

            $client = Client::find($id);
            if (!$client) {
                return $this->notFoundResponse('Клиент не найден');
            }

            if (!$user->is_admin && $client->user_id != $user->id) {
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
}
