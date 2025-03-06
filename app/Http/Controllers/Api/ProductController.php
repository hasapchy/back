<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Repositories\ProductsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    protected $itemsRepository;

    public function __construct(ProductsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    // Метод для получения продуктов с пагинацией
    public function products(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Получаем склад с пагинацией
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20);

        return response()->json([
            'items' => $items->items(),  // Список
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),  // Общее количество страниц
            'total' => $items->total()  // Общее количество
        ]);
    }

    // Поиск
    public function search(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $search = $request->query('search');

        $items = $this->itemsRepository->searchItems($userUuid, $search);

        return response()->json($items);
    }

    public function services(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Получаем склад с пагинацией
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20, false);

        return response()->json([
            'items' => $items->items(),  // Список
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),  // Общее количество страниц
            'total' => $items->total()  // Общее количество
        ]);
    }

    // метод 
    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $data = $request->validate([
            'type' => 'required',
            'image' => 'nullable|sometimes|file|mimes:jpeg,png,jpg,gif|max:2048',
            'name' => 'required|string|max:255',
            'description' => 'nullable|sometimes|string|max:255',
            'sku' => 'required|string|unique:products,sku',
            'barcode' => 'nullable|string',
            'status_id' => 'required|exists:product_statuses,id',
            // 'currency_id' => 'required|exists:currencies,id',
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'nullable|sometimes|exists:units,id',
            'retail_price' => 'nullable|numeric|min:0.01',
            'wholesale_price' => 'nullable|numeric|min:0.01',
            'purchase_price' => 'nullable|numeric|min:0',
        ]);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $this->itemsRepository->createItem($data);

        return response()->json(['message' => 'Product successfully created'], 200);
    }

    // метод для обновления продукта
    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $product_exist = Product::where('id', $id)->first();
        if (!$product_exist) {
            return response()->json(['message' => 'Product not found'], 404);
        }


        $data = $request->validate([
            'type' => 'nullable|integer',
            'image' => 'nullable|sometimes|file|mimes:jpeg,png,jpg,gif|max:2048',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'status_id' => 'nullable|exists:product_statuses,id',
            // 'currency_id' => 'nullable|exists:currencies,id',
            'category_id' => 'nullable|exists:categories,id',
            'unit_id' => 'nullable|exists:units,id',
            'retail_price' => 'nullable|numeric|min:0.01',
            'wholesale_price' => 'nullable|numeric|min:0.01',
            'purchase_price' => 'nullable|numeric|min:0',
        ]);

        $data = array_filter($data, function ($value) {
            return !is_null($value);
        });


        $product = $this->itemsRepository->updateItem($id, $data);

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $data['image'] = $request->file('image')->store('products', 'public');
            $product = $this->itemsRepository->updateItem($id, $data);
        }

        return response()->json(['message' => 'Product successfully updated'], 200);
    }
}
