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

    public function products(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->query('page', 1);
        $per_page = $request->query('per_page', 10);
        $warehouseId = $request->query('warehouse_id');
        $search = $request->query('search');
        $categoryId = $request->query('category_id');

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, true, $page, $warehouseId, $search, $categoryId);

        return $this->paginatedResponse($items);
    }

    public function search(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $search = $request->query('search');
        $productsOnly = $request->query('products_only');
        $warehouseId = $request->query('warehouse_id');

        $items = $this->itemsRepository->searchItems($userUuid, $search, $productsOnly, $warehouseId);

        return response()->json($items);
    }

    public function show(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $product = Product::find($id);
        if (!$product) {
            return $this->notFoundResponse('Product not found');
        }

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('products', 'view', $product)) {
            return $this->forbiddenResponse('У вас нет прав на просмотр этого товара');
        }

        $product = $this->itemsRepository->getItemById($id, $userUuid);

        return response()->json(['item' => $product]);
    }

    public function services(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->query('page', 1);
        $warehouseId = $request->query('warehouse_id');
        $search = $request->query('search');
        $categoryId = $request->query('category_id');

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20, false, $page, $warehouseId, $search, $categoryId);

        return $this->paginatedResponse($items);
    }

    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        if ($request->has('categories')) {
            $categories = $request->input('categories');
            if (is_string($categories)) {
                $categories = explode(',', $categories);
                $categories = array_map('trim', $categories);
                $categories = array_filter($categories);
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
            'category_id' => 'nullable|exists:categories,id',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'unit_id' => 'nullable|sometimes|exists:units,id',
            'retail_price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'date' => 'nullable|date',
            'user_id' => 'nullable|exists:users,id',
        ]);

        if (empty($data['category_id']) && empty($data['categories'])) {
            return $this->errorResponse('Необходимо указать хотя бы одну категорию', 422);
        }

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = $this->itemsRepository->createItem($data);

        CacheService::invalidateProductsCache();

        return response()->json(['item' => $product, 'message' => 'Product successfully created']);
    }

    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $product_exist = Product::where('id', $id)->first();
        if (!$product_exist) {
            return $this->notFoundResponse('Product not found');
        }

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('products', 'update', $product_exist)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этого товара');
        }

        if ($request->has('categories')) {
            $categories = $request->input('categories');
            if (is_string($categories)) {
                $categories = explode(',', $categories);
                $categories = array_map('trim', $categories);
                $categories = array_filter($categories);
                $request->merge(['categories' => $categories]);
            }
        }

        $data = $request->validate([
            'type' => 'nullable|integer',
            'image' => 'nullable|sometimes|file|mimes:jpeg,png,jpg,gif|max:2048',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'categories' => 'nullable|array',
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

        CacheService::invalidateProductsCache();

        return response()->json(['item' => $product, 'message' => 'Product successfully updated']);
    }

    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $product = Product::find($id);
        if (!$product) {
            return $this->notFoundResponse('Товар не найден');
        }

        // Проверяем права с учетом _all/_own
        if (!$this->canPerformAction('products', 'delete', $product)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этого товара');
        }

        $result = $this->itemsRepository->deleteItem($id);

        if (!$result['success']) {
            return $this->errorResponse($result['message'], 400);
        }

        CacheService::invalidateProductsCache();

        return response()->json(['message' => 'Товар/услуга успешно удалена']);
    }
}
