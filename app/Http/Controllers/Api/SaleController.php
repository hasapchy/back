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

    // Метод для получения продаж с пагинацией
    public function index(Request $request)
    {

        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        // Получаем сток с пагинацией
        $items = $this->itemRepository->getItemsWithPagination($userUuid, 20);

        return response()->json([
            'items' => $items->items(),  // Список 
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),  // Общее количество страниц
            'total' => $items->total()  // Общее количество
        ]);
    }

    // Метод создания продажи

    // public function store(Request $request)
    // {
    //     $userUuid = optional(auth('api')->user())->id;
    //     if (!$userUuid) {
    //         return response()->json(array('message' => 'Unauthorized'), 401);
    //     }
    //     // Валидация данных
    //     $request->validate([
    //         'client_id' => 'required|integer|exists:clients,id',
    //         'project_id' => 'nullable|sometimes|integer|exists:projects,id',
    //         'cash_id' => 'nullable|sometimes|integer|exists:cash_registers,id',
    //         'warehouse_id' => 'required|integer|exists:warehouses,id',
    //         'currency_id'  => 'nullable|integer|exists:currencies,id',
    //         'discount'      => 'nullable|numeric|min:0',
    //         'discount_type' => 'nullable|in:fixed,percent|required_with:discount',
    //         'date' => 'nullable|date',
    //         'note' => 'nullable|string',
    //         'products' => 'required|array',
    //         'products.*.product_id' => 'required|integer|exists:products,id',
    //         'products.*.quantity' => 'required|numeric|min:0',
    //         'products.*.price' => 'required|numeric|min:0'
    //     ]);

    //     $data = array(
    //         'user_id' => $userUuid,
    //         'client_id' => $request->client_id,
    //         'project_id' => $request->project_id,
    //         'cash_id' => $request->cash_id,
    //         'warehouse_id' => $request->warehouse_id,
    //         'currency_id' => $request->currency_id,
    //         'discount' => $request->discount ?? 0,
    //         'discount_type' => $request->discount_type ?? 'percent',
    //         'date' => $request->date ?? now(),
    //         'note' => $request->note ?? '',

    //         'products' => array_map(function ($product) {
    //             return [
    //                 'product_id' => $product['product_id'],
    //                 'quantity' => $product['quantity'],
    //                 'price' => $product['price']
    //             ];
    //         }, $request->products)
    //     );

    //     // Оприходуем
    //     try {
    //         $sale_created = $this->itemRepository->createItem($data);
    //         if (!$sale_created) {
    //             return response()->json([
    //                 'message' => 'Ошибка продажи'
    //             ], 400);
    //         }
    //         return response()->json([
    //             'message' => 'Продажа добавлена'
    //         ]);
    //     } catch (\Throwable $th) {
    //         return response()->json([
    //             'message' => 'Ошибка продажи' . $th->getMessage()
    //         ], 400);
    //     }
    // }

    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // 1) Валидация
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

        // 2) Собираем данные
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

        // 3) Создаём
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
