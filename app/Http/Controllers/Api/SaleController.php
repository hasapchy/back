<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\SalesRepository;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    protected $itemRepository;

    public function __construct(SalesRepository $itemRepository)
    {
        $this->itemRepository = $itemRepository;
    }

    public function index(Request $request)
    {

        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $search = $request->input('search');
        $dateFilter = $request->input('date_filter_type', 'all_time');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $items = $this->itemRepository->getItemsWithPagination($userUuid, 20, $search, $dateFilter, $startDate, $endDate);

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
            'client_id'     => 'required|integer|exists:clients,id',
            'project_id'    => 'nullable|integer|exists:projects,id',
            'type'          => 'required|in:cash,balance',
            'cash_id'       => 'nullable|integer|exists:cash_registers,id',
            'warehouse_id'  => 'required|integer|exists:warehouses,id',
            'currency_id'   => 'nullable|integer|exists:currencies,id',
            'discount'      => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percent|required_with:discount',
            'date'          => 'nullable|date',
            'note'          => 'nullable|string',
            'products'      => 'required|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity'   => 'required|numeric|min:1',
            'products.*.price'      => 'required|numeric|min:0',
        ]);

        $data = [
            'user_id'       => $userUuid,
            'client_id'     => $request->client_id,
            'project_id'    => $request->project_id,
            'type'          => $request->type,
            'cash_id'       => $request->cash_id,
            'warehouse_id'  => $request->warehouse_id,
            'currency_id'   => $request->currency_id,
            'discount'      => $request->discount  ?? 0,
            'discount_type' => $request->discount_type ?? 'percent',
            'date'          => $request->date      ?? now(),
            'note'          => $request->note      ?? '',
            'products'      => array_map(fn($p) => [
                'product_id' => $p['product_id'],
                'quantity'   => $p['quantity'],
                'price'      => $p['price'],
            ], $request->products),
        ];

        try {
            $ok = $this->itemRepository->createItem($data);
            if (! $ok) {
                return response()->json(['message' => 'Ошибка создания продажи'], 400);
            }
            return response()->json(['message' => 'Продажа добавлена'], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Ошибка продажи: ' . $e->getMessage()
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

    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $sale = $this->itemRepository->deleteItem($id);
            return response()->json([
                'message' => 'Продажа удалена успешно',
                'sale' => $sale
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка при удалении продажи: ' . $th->getMessage()
            ], 400);
        }
    }
}
