<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrdersRepository;
use App\Repositories\OrderAfRepository;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    protected $itemRepository;
    protected $orderAfRepository;

    public function __construct(OrdersRepository $itemRepository, OrderAfRepository $orderAfRepository)
    {
        $this->itemRepository = $itemRepository;
        $this->orderAfRepository = $orderAfRepository;
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
        $statusFilter = $request->input('status_ids');

        $items = $this->itemRepository->getItemsWithPagination($userUuid, 20, $search, $dateFilter, $startDate, $endDate, $statusFilter, $page);

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
            'project_id' => 'nullable|sometimes|integer|exists:projects,id',
            'cash_id' => 'required|integer|exists:cash_registers,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'currency_id' => 'nullable|integer|exists:currencies,id',
            'discount'      => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percent|required_with:discount',
            'category_id' => 'nullable|integer|exists:order_categories,id',
            'description' => 'nullable|string',
            'date' => 'nullable|date',
            'note' => 'nullable|string',
            'description' => 'nullable|string',
            'status_id'    => 'nullable|integer|exists:order_statuses,id',
            'products'              => 'sometimes|array',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity'   => 'required_with:products|numeric|min:0.01',
            'products.*.price'      => 'required_with:products|numeric|min:0',
            'temp_products'         => 'sometimes|array',
            'temp_products.*.name'  => 'required_with:temp_products|string|max:255',
            'temp_products.*.description' => 'nullable|string',
            'temp_products.*.quantity'    => 'required_with:temp_products|numeric|min:0.01',
            'temp_products.*.price'       => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.unit_id'     => 'nullable|exists:units,id',
            'additional_fields' => 'sometimes|array',
            'additional_fields.*.field_id' => 'required_with:additional_fields|integer|exists:order_af,id',
            'additional_fields.*.value' => 'required_with:additional_fields|string|max:1000',
        ]);

        $data = [
            'user_id'      => $userUuid,
            'client_id'    => $request->client_id,
            'project_id'   => $request->project_id,
            'cash_id'      => $request->cash_id,
            'warehouse_id' => $request->warehouse_id,
            'currency_id' => $request->currency_id,
            'discount' => $request->discount ?? 0,
            'discount_type' => $request->discount_type ?? 'percent',
            'category_id' => $request->category_id,
            'description' => $request->description,
            'date'         => $request->date ?? now(),
            'note'         => $request->note ?? '',
            'description' => $request->description ?? '',
            'status_id'    => 1,
            'products'     => array_map(fn($p) => [
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'price'      => $p['price'],
            ], $request->products ?? []),
            'temp_products' => array_map(fn($p) => [
                'name'        => $p['name'],
                'description' => $p['description'] ?? null,
                'quantity'    => $p['quantity'],
                'price'       => $p['price'],
                'unit_id'     => $p['unit_id'] ?? null,
            ], $request->temp_products ?? []),
            'additional_fields' => $request->additional_fields ?? [],
        ];

        // Проверяем права доступа к кассе
        $userHasPermissionToCashRegister = $this->itemRepository->userHasPermissionToCashRegister($userUuid, $request->cash_id);
        if (!$userHasPermissionToCashRegister) {
            return response()->json(['message' => 'У вас нет прав на эту кассу'], 403);
        }

        try {
            $created = $this->itemRepository->createItem($data);

            if (!$created) {
                return response()->json(['message' => 'Ошибка создания заказа'], 400);
            }

            return response()->json(['message' => 'Заказ успешно создан']);
        } catch (\Throwable $th) {
            return response()->json(['message' => 'Ошибка заказа: ' . $th->getMessage()], 400);
        }
    }
    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'client_id'            => 'required|integer|exists:clients,id',
            'project_id'           => 'nullable|sometimes|integer|exists:projects,id',
            'cash_id'              => 'required|integer|exists:cash_registers,id',
            'warehouse_id'         => 'required|integer|exists:warehouses,id',
            'currency_id'  => 'nullable|integer|exists:currencies,id',
            'date'                 => 'nullable|date',
            'note'                 => 'nullable|string',
            'description'          => 'nullable|string',
            'status_id'            => 'nullable|integer|exists:order_statuses,id',
            'category_id'          => 'nullable|integer|exists:order_categories,id',
            'products'             => 'nullable|array',
            'products.*.product_id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity'  => 'required_with:products|numeric|min:0.01',
            'products.*.price'     => 'required_with:products|numeric|min:0',
            'temp_products'         => 'nullable|array',
            'temp_products.*.name'  => 'required_with:temp_products|string|max:255',
            'temp_products.*.description' => 'nullable|string',
            'temp_products.*.quantity'    => 'required_with:temp_products|numeric|min:0.01',
            'temp_products.*.price'       => 'required_with:temp_products|numeric|min:0',
            'temp_products.*.unit_id'     => 'nullable|exists:units,id',
            'additional_fields' => 'sometimes|array',
            'additional_fields.*.field_id' => 'required_with:additional_fields|integer|exists:order_af,id',
            'additional_fields.*.value' => 'required_with:additional_fields|string|max:1000',
        ]);

        $data = [
            'user_id'      => $userUuid,
            'client_id'    => $request->client_id,
            'project_id'   => $request->project_id,
            'cash_id'      => $request->cash_id,
            'warehouse_id'  => $request->warehouse_id,
            'currency_id'   => $request->currency_id,
            'discount'      => $request->discount  ?? 0,
            'discount_type' => $request->discount_type ?? 'percent',
            'warehouse_id' => $request->warehouse_id,
            'note'         => $request->note ?? '',
            'description'  => $request->description ?? '',
            'status_id'    => $request->status_id,
            'category_id'  => $request->category_id,
            'date'         => $request->date ?? now(),
            'products'     => array_map(fn($p) => [
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'price'      => $p['price'],
            ], $request->products ?? []),
            'temp_products' => array_map(fn($p) => [
                'name'        => $p['name'],
                'description' => $p['description'] ?? null,
                'quantity'    => $p['quantity'],
                'price'       => $p['price'],
                'unit_id'     => $p['unit_id'] ?? null,
            ], $request->temp_products ?? []),
            'additional_fields' => $request->additional_fields ?? [],
        ];

        // Проверяем права доступа к кассе
        $userHasPermissionToCashRegister = $this->itemRepository->userHasPermissionToCashRegister($userUuid, $request->cash_id);
        if (!$userHasPermissionToCashRegister) {
            return response()->json(['message' => 'У вас нет прав на эту кассу'], 403);
        }

        try {
            $updated = $this->itemRepository->updateItem($id, $data);
            if (!$updated) {
                return response()->json(['message' => 'Ошибка обновления заказа'], 400);
            }
            return response()->json(['message' => 'Заказ сохранён']);
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

        // Получаем заказ для проверки прав доступа к кассе
        $order = $this->itemRepository->getItemById($id);
        if (!$order) {
            return response()->json(['message' => 'Заказ не найден'], 404);
        }

        // Проверяем права доступа к кассе
        $userHasPermissionToCashRegister = $this->itemRepository->userHasPermissionToCashRegister($userUuid, $order->cash_id);
        if (!$userHasPermissionToCashRegister) {
            return response()->json(['message' => 'У вас нет прав на эту кассу'], 403);
        }

        try {
            $deleted = $this->itemRepository->deleteItem($id);
            return response()->json([
                'message' => 'Заказ успешно удалён',
                'order' => $deleted
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка при удалении заказа: ' . $th->getMessage()
            ], 400);
        }
    }
    public function batchUpdateStatus(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'ids'       => 'required|array|min:1',
            'ids.*'     => 'integer|exists:orders,id',
            'status_id' => 'required|integer|exists:order_statuses,id',
        ]);

        try {
            $affected = $this->itemRepository
                ->updateStatusByIds($request->ids, $request->status_id, $userUuid);

            if ($affected > 0) {
                return response()->json([
                    'message' => "Статус обновлён у {$affected} заказ(ов)"
                ]);
            } else {
                return response()->json([
                    'message' => "Статус не изменился"
                ]);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => $th->getMessage() ?: 'Ошибка смены статуса'
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

        // Проверяем права доступа к кассе
        $userHasPermissionToCashRegister = $this->itemRepository->userHasPermissionToCashRegister($userUuid, $item->cash_id);
        if (!$userHasPermissionToCashRegister) {
            return response()->json(['message' => 'У вас нет прав на эту кассу'], 403);
        }

        return response()->json(['item' => $item]);
    }

    public function getAdditionalFields($categoryId)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $fields = $this->orderAfRepository->getFieldsByCategory($categoryId, $userUuid);

        return response()->json([
            'category_id' => $categoryId,
            'fields' => $fields
        ]);
    }

    public function getAdditionalFieldsByCategories(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'integer|exists:order_categories,id',
        ]);

        $fields = $this->orderAfRepository->getFieldsByCategories($request->category_ids, $userUuid);

        return response()->json([
            'category_ids' => $request->category_ids,
            'fields' => $fields
        ]);
    }
}
