<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Repositories\ClientsRepository;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

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
        $items = $this->itemsRepository->getItemsPaginated($perPage, $search, $includeInactive, $page, $statusFilter, $typeFilter);

        return response()->json([
            'items' => $items->items(),  // Список
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),  // Общее количество страниц
            'total' => $items->total()  // Общее количество
        ]);
    }

    public function search(Request $request)
    {
        $search_request = $request->input('search_request');

        if (!$search_request || empty($search_request)) {
            $items = [];
        } else {
            // Балансы теперь в колонке clients.balance

            $items = $this->itemsRepository->searchClient($search_request);
        }

        // Баланс теперь уже число из колонки clients.balance

        return response()->json($items);
    }

        public function show($id)
    {
        try {
            // Балансы теперь в колонке clients.balance

            // Инвалидируем кэш клиента, чтобы всегда получать актуальный баланс для формы редактирования
            if (method_exists($this->itemsRepository, 'invalidateClientBalanceCache')) {
                $this->itemsRepository->invalidateClientBalanceCache($id);
            }

            $client = $this->itemsRepository->getItem($id);

            if (!$client) {
                return response()->json([
                    'message' => 'Client not found'
                ], 404);
            }

            // Баланс теперь уже число из колонки clients.balance

            return response()->json([
                'item' => $client
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Ошибка при получении клиента',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getBalanceHistory($id)
    {
        try {
            $history = $this->itemsRepository->getBalanceHistory($id);

            return response()->json([
                'history' => $history
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Ошибка при получении истории баланса',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function all()
    {
        try {
            // Получаем всех клиентов без пагинации (большой per_page)
            $items = $this->itemsRepository->getItemsPaginated(1000, null, false, 1);
            return response()->json($items->items());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Ошибка при получении всех клиентов',
                'error' => $e->getMessage()
            ], 500);
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

        // Проверка на дублирование employee_id (для любых типов клиентов)
        if (!empty($validatedData['employee_id'])) {
            $companyId = $request->header('X-Company-ID');
            $existingClient = Client::where('employee_id', $validatedData['employee_id'])
                ;

            if ($companyId) {
                $existingClient->where('company_id', $companyId);
            } else {
                $existingClient->whereNull('company_id');
            }

            if ($existingClient->exists()) {
                return response()->json([
                    'message' => 'Этот пользователь уже привязан к другому клиенту'
                ], 422);
            }
        }

        // Проверка уникальности телефонов внутри компании
        $companyId = $request->header('X-Company-ID');
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
                    return response()->json([
                        'message' => "Телефон {$phone} уже используется другим клиентом в этой компании"
                    ], 422);
                }
            }
        }

        DB::beginTransaction();
        try {
            // Добавляем user_id к данным
            $validatedData['user_id'] = auth('api')->id();
            $client = $this->itemsRepository->create($validatedData);

            DB::commit();

            // Инвалидируем кэш клиентов
            \App\Services\CacheService::invalidateClientsCache();
            \App\Services\CacheService::invalidateOrdersCache();
            \App\Services\CacheService::invalidateSalesCache();
            \App\Services\CacheService::invalidateTransactionsCache();

            return response()->json([
                'message' => 'Client created successfully',
                'item' => $client->load('phones', 'emails', 'employee'),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            // Проверяем на ошибку дублирования email
            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return response()->json([
                    'message' => 'Email уже используется другим клиентом'
                ], 422);
            }

            return response()->json([
                'message' => 'Ошибка при создании клиента',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function update(Request $request, $id)
    {
        $user = auth('api')->user();

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
            // Получаем клиента для проверки владельца
            $existingClient = Client::find($id);
            if (!$existingClient) {
                return response()->json(['message' => 'Клиент не найден'], 404);
            }

            // Проверяем права владельца: если не админ, то можно редактировать только свои записи
            if (!$user->is_admin && $existingClient->user_id != $user->id) {
                return response()->json([
                    'message' => 'У вас нет прав на редактирование этого клиента'
                ], 403);
            }

            // Проверка на дублирование employee_id (для любых типов клиентов)
            if (!empty($validatedData['employee_id'])) {
                $companyId = $request->header('X-Company-ID');
                $duplicateClient = Client::where('employee_id', $validatedData['employee_id'])
                    ->where('id', '!=', $id); // Исключаем текущего клиента

                if ($companyId) {
                    $duplicateClient->where('company_id', $companyId);
                } else {
                    $duplicateClient->whereNull('company_id');
                }

                if ($duplicateClient->exists()) {
                    return response()->json([
                        'message' => 'Этот пользователь уже привязан к другому клиенту'
                    ], 422);
                }
            }

            // Проверка уникальности телефонов внутри компании
            $companyId = $request->header('X-Company-ID');
            if (!empty($validatedData['phones'])) {
                foreach ($validatedData['phones'] as $phone) {
                    $existingPhone = DB::table('clients_phones')
                        ->join('clients', 'clients_phones.client_id', '=', 'clients.id')
                        ->where('clients_phones.phone', $phone)
                        ->where('clients_phones.client_id', '!=', $id); // Исключаем текущего клиента

                    if ($companyId) {
                        $existingPhone->where('clients.company_id', $companyId);
                    } else {
                        $existingPhone->whereNull('clients.company_id');
                    }

                    if ($existingPhone->exists()) {
                        return response()->json([
                            'message' => "Телефон {$phone} уже используется другим клиентом в этой компании"
                        ], 422);
                    }
                }
            }

            $client = $this->itemsRepository->update($id, $validatedData);

            // Инвалидируем кэш клиентов
            \App\Services\CacheService::invalidateClientsCache();
            // Инвалидируем кэш сущностей где клиент embedded (заказы, продажи, транзакции)
            \App\Services\CacheService::invalidateOrdersCache();
            \App\Services\CacheService::invalidateSalesCache();
            \App\Services\CacheService::invalidateTransactionsCache();

            return response()->json([
                'message' => 'Client updated successfully',
                'client' => $client
            ], 200);
        } catch (\Throwable $e) {
            // Проверяем на ошибку дублирования email
            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return response()->json([
                    'message' => 'Email уже используется другим клиентом'
                ], 422);
            }

            return response()->json([
                'message' => 'Ошибка при обновлении клиента',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = auth('api')->user();

            // Получаем клиента для проверки владельца
            $client = Client::find($id);
            if (!$client) {
                return response()->json(['message' => 'Клиент не найден'], 404);
            }

            // Проверяем права владельца: если не админ, то можно удалять только свои записи
            if (!$user->is_admin && $client->user_id != $user->id) {
                return response()->json([
                    'message' => 'У вас нет прав на удаление этого клиента'
                ], 403);
            }

            // Проверка на наличие транзакций
            $hasTransactions = DB::table('transactions')->where('client_id', $id)->exists();

            if ($hasTransactions) {
                return response()->json([
                    'message' => 'Нельзя удалить клиента: найдены связанные транзакции.'
                ], 422);
            }

            // Проверка на наличие заказов
            $hasOrders = DB::table('orders')->where('client_id', $id)->exists();

            if ($hasOrders) {
                return response()->json([
                    'message' => 'Нельзя удалить клиента: найдены связанные заказы.'
                ], 422);
            }

            // Проверка баланса
            $balance = DB::table('clients')->where('id', $id)->value('balance');

            if ($balance > 0 || $balance < 0) {
                return response()->json([
                    'message' => 'Нельзя удалить клиента с ненулевым балансом.'
                ], 422);
            }

            $deleted = $this->itemsRepository->deleteItem($id);

            if ($deleted) {
                // Инвалидируем кэш клиентов
                \App\Services\CacheService::invalidateClientsCache();
                // Инвалидируем кэш сущностей где клиент embedded
                \App\Services\CacheService::invalidateOrdersCache();
                \App\Services\CacheService::invalidateSalesCache();
                \App\Services\CacheService::invalidateTransactionsCache();

                return response()->json(['message' => 'Клиент успешно удалён'], 200);
            } else {
                return response()->json(['message' => 'Клиент не найден'], 404);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Ошибка при удалении клиента',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
