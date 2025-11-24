<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Repositories\ProductsRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Контроллер для работы с товарами и услугами
 */
class ProductController extends Controller
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param ProductsRepository $itemsRepository
     */
    public function __construct(ProductsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список товаров с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function products(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->query('page', 1);
        $per_page = $request->query('per_page', 10);
        $warehouseId = $request->query('warehouse_id');
        $search = $request->query('search');
        $categoryId = $this->normalizeCategoryIdForBasementWorker($request->query('category_id'));

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, true, $page, $warehouseId, $search, $categoryId);

        return ProductResource::collection($items)->response();
    }

    /**
     * Поиск товаров и услуг
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $search = $request->query('search');
        $productsOnly = $request->query('products_only');
        $warehouseId = $request->query('warehouse_id');
        $categoryId = $this->normalizeCategoryIdForBasementWorker($request->query('category_id'));

        $items = $this->itemsRepository->searchItems($userUuid, $search, $productsOnly, $warehouseId, $categoryId);

        return ProductResource::collection($items)->response();
    }

    /**
     * Получить товар/услугу по ID
     *
     * @param Request $request
     * @param int $id ID товара/услуги
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $product = Product::findOrFail($id);

        if (!$this->canPerformAction('products', 'view', $product)) {
            return $this->forbiddenResponse('У вас нет прав на просмотр этого товара');
        }

        $product = $this->itemsRepository->getItemById($id, $userUuid);

        return $this->dataResponse(new ProductResource($product));
    }

    /**
     * Получить список услуг с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function services(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->query('page', 1);
        $warehouseId = $request->query('warehouse_id');
        $search = $request->query('search');
        $categoryId = $this->normalizeCategoryIdForBasementWorker($request->query('category_id'));

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20, false, $page, $warehouseId, $search, $categoryId);

        return ProductResource::collection($items)->response();
    }

    /**
     * Создать новый товар/услугу
     *
     * @param StoreProductRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreProductRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = $this->itemsRepository->createItem($data);
        $product = Product::with(['categories', 'unit', 'prices', 'creator'])->findOrFail($product->id);

        return $this->dataResponse(new ProductResource($product), 'Product successfully created');
    }

    /**
     * Обновить товар/услугу
     *
     * @param UpdateProductRequest $request
     * @param int $id ID товара/услуги
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateProductRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $product_exist = Product::findOrFail($id);

        if (!$this->canPerformAction('products', 'update', $product_exist)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этого товара');
        }

        $data = $request->validated();
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
        $product = Product::with(['categories', 'unit', 'prices', 'creator'])->findOrFail($id);

        return $this->dataResponse(new ProductResource($product), 'Product successfully updated');
    }

    /**
     * Удалить товар/услугу
     *
     * @param int $id ID товара/услуги
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $product = Product::findOrFail($id);

        if (!$this->canPerformAction('products', 'delete', $product)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этого товара');
        }

        $result = $this->itemsRepository->deleteItem($id);

        if (!$result['success']) {
            return $this->errorResponse($result['message'], 400);
        }

        return response()->json(['message' => 'Товар/услуга успешно удалена']);
    }

    /**
     * Нормализует categoryId для basement workers
     * Для basement workers всегда возвращает null, чтобы использовать все доступные категории
     *
     * @param mixed $categoryId
     * @return int|null
     */
    protected function normalizeCategoryIdForBasementWorker($categoryId)
    {
        $user = auth('api')->user();
        $isBasementWorker = $user instanceof User && $user->hasRole(config('basement.worker_role'));

        return $isBasementWorker ? null : $categoryId;
    }
}
