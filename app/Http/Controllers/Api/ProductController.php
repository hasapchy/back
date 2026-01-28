<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Models\User;
use App\Repositories\ProductsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Контроллер для работы с товарами и услугами
 */
class ProductController extends BaseController
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
        $per_page = $request->query('per_page', 20);
        $warehouseId = $request->query('warehouse_id');
        $search = $request->query('search');
        $categoryId = $this->normalizeCategoryIdForSimpleWorker($request->query('category_id'));

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, true, $page, $warehouseId, $search, $categoryId);

        return $this->paginatedResponse($items);
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
        $categoryId = $this->normalizeCategoryIdForSimpleWorker($request->query('category_id'));

        $items = $this->itemsRepository->searchItems($userUuid, $search, $productsOnly, $warehouseId, $categoryId);

        return response()->json($items);
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

        return response()->json(['item' => $product]);
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
        $per_page = $request->query('per_page', 20);
        $warehouseId = $request->query('warehouse_id');
        $search = $request->query('search');
        $categoryId = $this->normalizeCategoryIdForSimpleWorker($request->query('category_id'));

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, false, $page, $warehouseId, $search, $categoryId);

        return $this->paginatedResponse($items);
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

        return response()->json(['item' => $product, 'message' => 'Product successfully created']);
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

        return response()->json(['item' => $product, 'message' => 'Product successfully updated']);
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
     * Нормализует categoryId для simple workers
     * Для simple workers возвращает null, чтобы фильтрация происходила через getUserCategoryIds
     * который учитывает маппинг из конфига и подкатегории
     *
     * @param mixed $categoryId
     * @return int|null
     */
    protected function normalizeCategoryIdForSimpleWorker($categoryId)
    {
        $user = auth('api')->user();
        $isSimpleWorker = $user instanceof User && $user->hasRole(config('simple.worker_role'));

        return $isSimpleWorker ? null : $categoryId;
    }
}
