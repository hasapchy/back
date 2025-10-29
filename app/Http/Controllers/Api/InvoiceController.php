<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\InvoicesRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    protected $itemRepository;

    public function __construct(InvoicesRepository $itemRepository)
    {
        $this->itemRepository = $itemRepository;
    }

    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $page = $request->input('page', 1);
        $search = $request->input('search');
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $typeFilter = $request->input('type');
        $statusFilter = $request->input('status');
        $perPage = $request->input('per_page', 20);

        $items = $this->itemRepository->getItemsWithPagination($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $typeFilter, $statusFilter, $page);

        return response()->json([
            'items' => $items->items(),
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'total' => $items->total()
        ]);
    }

    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'invoice_date' => 'nullable|date',
            'note' => 'nullable|string',
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        try {
            // Получаем заказы для счета
            $orders = $this->itemRepository->getOrdersForInvoice($request->order_ids);

            if ($orders->isEmpty()) {
                return response()->json(['message' => 'Заказы не найдены'], 400);
            }

            // Проверяем, что все заказы принадлежат одному клиенту
            $clientId = $orders->first()->client_id;
            if ($orders->where('client_id', '!=', $clientId)->isNotEmpty()) {
                return response()->json(['message' => 'Все заказы должны принадлежать одному клиенту'], 400);
            }

            // Подготавливаем товары из заказов
            $productsData = $this->itemRepository->prepareProductsFromOrders($orders);
            $products = $productsData['products'];

            // Рассчитываем суммы
            $totalAmount = collect($products)->sum('total_price');

            $data = [
                'client_id' => $request->client_id,
                'user_id' => $userUuid,
                'invoice_date' => $request->invoice_date ?? now()->toDateString(),
                'note' => $request->note ?? '',
                'order_ids' => $request->order_ids,
                'products' => $products,
                'total_amount' => $totalAmount,
            ];

            $created = $this->itemRepository->createItem($data);

            if (!$created) {
                return response()->json(['message' => 'Ошибка создания счета'], 400);
            }

            // Инвалидируем кэш счетов
            CacheService::invalidateInvoicesCache();

            return response()->json(['message' => 'Счет успешно создан', 'invoice' => $created]);
        } catch (\Throwable $th) {
            return response()->json(['message' => 'Ошибка создания счета: ' . $th->getMessage()], 400);
        }
    }

    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'invoice_date' => 'nullable|date',
            'note' => 'nullable|string',
            'status' => 'nullable|string|in:new,in_progress,paid,cancelled',
            'order_ids' => 'nullable|array',
            'order_ids.*' => 'integer|exists:orders,id',
            'products' => 'nullable|array',
            'products.*.product_name' => 'required_with:products|string|max:255',
            'products.*.quantity' => 'required_with:products|numeric|min:0.01',
            'products.*.price' => 'required_with:products|numeric|min:0',
        ]);

        try {
            $data = [
                'client_id' => $request->client_id,
                'invoice_date' => $request->invoice_date,
                'note' => $request->note,
                'status' => $request->status,
                'order_ids' => $request->order_ids,
                'products' => $request->products,
                'total_amount' => $request->total_amount,
            ];

            $updated = $this->itemRepository->updateItem($id, $data);
            if (!$updated) {
                return response()->json(['message' => 'Ошибка обновления счета'], 400);
            }

            // Инвалидируем кэш счетов
            CacheService::invalidateInvoicesCache();

            return response()->json(['message' => 'Счет сохранён']);
        } catch (\Throwable $th) {
            return response()->json(['message' => 'Ошибка: ' . $th->getMessage()], 400);
        }
    }

    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $deleted = $this->itemRepository->deleteItem($id);

            // Инвалидируем кэш счетов
            CacheService::invalidateInvoicesCache();

            return response()->json([
                'message' => 'Счет успешно удалён',
                'invoice' => $deleted
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка при удалении счета: ' . $th->getMessage()
            ], 400);
        }
    }

    public function show($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $item = $this->itemRepository->getItemById($id);
        if (!$item) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['item' => $item]);
    }

    public function getOrdersForInvoice(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        try {
            $orders = $this->itemRepository->getOrdersForInvoice($request->order_ids);
            $productsData = $this->itemRepository->prepareProductsFromOrders($orders);
            $products = $productsData['products'];
            $orderDate = $productsData['order_date'];

            return response()->json([
                'orders' => $orders,
                'products' => $products,
                'order_date' => $orderDate,
                'total_amount' => collect($products)->sum('total_price')
            ]);
        } catch (\Throwable $th) {
            return response()->json(['message' => 'Ошибка получения данных: ' . $th->getMessage()], 400);
        }
    }
}
