<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Repositories\ClientsRepository;
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
            // Создаем недостающие балансы перед поиском
            $this->itemsRepository->createMissingBalances();

            $items = $this->itemsRepository->searchClient($search_request);
        }

        // Приводим балансы к числам для всех клиентов
        if (is_array($items)) {
            foreach ($items as &$item) {
                if (isset($item['balance_amount'])) {
                    $item['balance_amount'] = (float) $item['balance_amount'];
                }
            }
        }

        return response()->json($items);
    }

        public function show($id)
    {
        try {
            // Создаем недостающие балансы перед получением клиента
            $this->itemsRepository->createMissingBalances();

            $client = $this->itemsRepository->getItem($id);

            if (!$client) {
                return response()->json([
                    'message' => 'Client not found'
                ], 404);
            }

            // Приводим баланс к числу
            if (isset($client['balance_amount'])) {
                $client['balance_amount'] = (float) $client['balance_amount'];
            }

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

        DB::beginTransaction();
        try {
            // Добавляем user_id к данным
            $validatedData['user_id'] = auth('api')->id();
            $client = $this->itemsRepository->create($validatedData);

            $client->balance()->create([
                'balance' => 0,
            ]);

            DB::commit();

            // Инвалидируем кэш клиентов
            \App\Services\CacheService::invalidateClientsCache();
            // Инвалидируем кэш сущностей где клиент embedded (заказы, продажи, транзакции)
            \App\Services\CacheService::invalidateOrdersCache();
            \App\Services\CacheService::invalidateSalesCache();
            \App\Services\CacheService::invalidateTransactionsCache();

            return response()->json([
                'message' => 'Client created successfully',
                'item' => $client->load('balance', 'phones', 'emails', 'employee'),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            // Проверяем на ошибку дублирования телефона
            if (str_contains($e->getMessage(), 'clients_phones_phone_unique')) {
                return response()->json([
                    'message' => 'Телефон уже используется другим клиентом'
                ], 422);
            }

            // Проверяем на ошибку дублирования email
            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return response()->json([
                    'message' => 'Email уже используется другим клиентом'
                ], 422);
            }

            return response()->json([
                'message' => 'Ошибка при создании клиента'
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
            $existingClient = \App\Models\Client::find($id);
            if (!$existingClient) {
                return response()->json(['message' => 'Клиент не найден'], 404);
            }

            // Проверяем права владельца: если не админ, то можно редактировать только свои записи
            if (!$user->is_admin && $existingClient->user_id != $user->id) {
                return response()->json([
                    'message' => 'У вас нет прав на редактирование этого клиента'
                ], 403);
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
            // Проверяем на ошибку дублирования телефона
            if (str_contains($e->getMessage(), 'clients_phones_phone_unique')) {
                return response()->json([
                    'message' => 'Телефон уже используется другим клиентом'
                ], 422);
            }

            // Проверяем на ошибку дублирования email
            if (str_contains($e->getMessage(), 'clients_emails_email_unique')) {
                return response()->json([
                    'message' => 'Email уже используется другим клиентом'
                ], 422);
            }

            return response()->json([
                'message' => 'Ошибка при обновлении клиента'
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = auth('api')->user();

            // Получаем клиента для проверки владельца
            $client = \App\Models\Client::find($id);
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
            $balance = DB::table('client_balances')->where('client_id', $id)->value('balance');

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
