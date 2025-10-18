<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Repositories\ProductsRepository;
use App\Services\CacheService;
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

        $page = $request->query('page', 1);
        $per_page = $request->query('per_page', 10);
        $filterByCategory1 = filter_var($request->query('filter_by_category_1', false), FILTER_VALIDATE_BOOLEAN);
        $warehouseId = $request->query('warehouse_id');
        $search = $request->query('search');
        $categoryId = $request->query('category_id');

        // Получаем продукты с пагинацией
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, true, $page, $warehouseId, $search, $categoryId);

        // DEBUG: логируем параметры и результат пагинации

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
        $type = $request->query('type', true); // По умолчанию ищем продукты
        $filterByCategory1 = filter_var($request->query('filter_by_category_1', false), FILTER_VALIDATE_BOOLEAN);
        $productsOnly = $request->query('products_only');
        $warehouseId = $request->query('warehouse_id');

        // productsOnly в репозитории — булево для фильтра типа, filterByCategory1 не используется здесь
        $items = $this->itemsRepository->searchItems($userUuid, $search, $productsOnly, $warehouseId);


        return response()->json($items);
    }

    // Получение товара по ID
    public function show(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $product = $this->itemsRepository->getItemById($id, $userUuid);

        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json($product);
    }

    public function services(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $page = $request->query('page', 1);
        $filterByCategory1 = filter_var($request->query('filter_by_category_1', false), FILTER_VALIDATE_BOOLEAN);

        $warehouseId = $request->query('warehouse_id');
        $search = $request->query('search');
        $categoryId = $request->query('category_id');

        // Получаем услуги с пагинацией
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20, false, $page, $warehouseId, $search, $categoryId);

        // DEBUG: логируем параметры и результат пагинации

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

        // Обрабатываем categories - если пришла строка, преобразуем в массив
        if ($request->has('categories')) {
            $categories = $request->input('categories');
            if (is_string($categories)) {
                // Если это строка с разделителями, разбиваем на массив
                $categories = explode(',', $categories);
                $categories = array_map('trim', $categories); // Убираем пробелы
                $categories = array_filter($categories); // Убираем пустые элементы
                $request->merge(['categories' => $categories]);
            }
        }

        $data = $request->validate([
            'type' => 'required',
            'image' => 'nullable|sometimes|file|mimes:jpeg,png,jpg,gif|max:2048',
            'name' => 'required|string|max:255',
            'description' => 'nullable|sometimes|string|max:255',
            'sku' => 'required|string|unique:products,sku',
            'barcode' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id', // Для обратной совместимости
            'categories' => 'nullable|array', // Множественные категории
            'categories.*' => 'exists:categories,id',
            'unit_id' => 'nullable|sometimes|exists:units,id',
            'retail_price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'date' => 'nullable|date',
            'user_id' => 'nullable|exists:users,id',
        ]);

        // Валидация: должна быть хотя бы одна категория
        if (empty($data['category_id']) && empty($data['categories'])) {
            return response()->json(['message' => 'Необходимо указать хотя бы одну категорию'], 422);
        }

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = $this->itemsRepository->createItem($data);

        // Инвалидируем кэш продуктов/услуг
        \App\Services\CacheService::invalidateProductsCache();

        return response()->json([
            'message' => 'Product successfully created',
            'item' => $product
        ], 200);
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

        // Обрабатываем categories - если пришла строка, преобразуем в массив
        if ($request->has('categories')) {
            $categories = $request->input('categories');
            if (is_string($categories)) {
                // Если это строка с разделителями, разбиваем на массив
                $categories = explode(',', $categories);
                $categories = array_map('trim', $categories); // Убираем пробелы
                $categories = array_filter($categories); // Убираем пустые элементы
                $request->merge(['categories' => $categories]);
            }
        }

        $data = $request->validate([
            'type' => 'nullable|integer',
            'image' => 'nullable|sometimes|file|mimes:jpeg,png,jpg,gif|max:2048',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'categories' => 'nullable|array', // Множественные категории
            'categories.*' => 'exists:categories,id',
            'unit_id' => 'nullable|exists:units,id',
            'retail_price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'date' => 'nullable|date',
            'user_id' => 'nullable|exists:users,id',
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

        // Инвалидируем кэш продуктов/услуг
        \App\Services\CacheService::invalidateProductsCache();

        return response()->json([
            'message' => 'Product successfully updated',
            'item' => $product
        ], 200);
    }

    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $result = $this->itemsRepository->deleteItem($id);

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 400);
        }

        // Инвалидируем кэш продуктов/услуг
        \App\Services\CacheService::invalidateProductsCache();

        return response()->json(['message' => 'Товар/услуга успешно удалена']);
    }
}
