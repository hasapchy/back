<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\SalesProduct;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WhReceiptProduct;
use App\Models\WhWriteoffProduct;
use App\Repositories\ProductsRepository;
use Illuminate\Http\JsonResponse;
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
     */
    public function __construct(ProductsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список товаров с пагинацией
     *
     * @return JsonResponse
     */
    public function products(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->query('page', 1);
        $per_page = $request->query('per_page', 20);
        $warehouseId = $request->query('warehouse_id');
        $search = $request->query('search');
        $categoryId = $this->normalizeCategoryIdForSimpleWorker($request->query('category_id'));
        $warehouseStockPolicy = $this->resolveWarehouseStockPolicy($request);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, true, $page, $warehouseId, $search, $categoryId, $warehouseStockPolicy);

        return $this->successResponse([
            'items' => ProductResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Поиск товаров и услуг
     *
     * @return JsonResponse
     */
    public function search(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $search = $request->query('search');
        $productsOnly = $request->query('products_only');
        $warehouseId = $request->query('warehouse_id');
        $categoryId = $this->normalizeCategoryIdForSimpleWorker($request->query('category_id'));
        $warehouseStockPolicy = $this->resolveWarehouseStockPolicy($request);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $result = $this->itemsRepository->searchItems($userUuid, $search, $productsOnly, $warehouseId, $categoryId, $warehouseStockPolicy, $page, $perPage);

        return $this->successResponse([
            'items' => ProductResource::collection($result['items'])->resolve(),
            'meta' => [
                'current_page' => $result['current_page'],
                'last_page' => $result['last_page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
            ],
        ]);
    }

    /**
     * Получить товар/услугу по ID
     *
     * @param  int  $id  ID товара/услуги
     * @return JsonResponse
     */
    public function show(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $product = Product::findOrFail($id);

        if (! $this->canPerformAction('products', 'view', $product)) {
            return $this->errorResponse('У вас нет прав на просмотр этого товара', 403);
        }

        $product = $this->itemsRepository->getItemById($id, $userUuid);

        return $this->successResponse(new ProductResource($product));
    }

    /**
     * Получить список услуг с пагинацией
     *
     * @return JsonResponse
     */
    public function services(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $page = $request->query('page', 1);
        $per_page = $request->query('per_page', 20);
        $warehouseId = $request->query('warehouse_id');
        $search = $request->query('search');
        $categoryId = $this->normalizeCategoryIdForSimpleWorker($request->query('category_id'));
        $warehouseStockPolicy = $this->resolveWarehouseStockPolicy($request);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, false, $page, $warehouseId, $search, $categoryId, $warehouseStockPolicy);

        return $this->successResponse([
            'items' => ProductResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Создать новый товар/услугу
     *
     * @return JsonResponse
     */
    public function store(StoreProductRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = $this->itemsRepository->createItem($data);

        return $this->successResponse(new ProductResource($product), 'Product successfully created');
    }

    /**
     * Обновить товар/услугу
     *
     * @param  int  $id  ID товара/услуги
     * @return JsonResponse
     */
    public function update(UpdateProductRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $product_exist = Product::findOrFail($id);

        if (! $this->canPerformAction('products', 'update', $product_exist)) {
            return $this->errorResponse('У вас нет прав на редактирование этого товара', 403);
        }

        $data = $request->validated();
        $data = array_filter($data, function ($value) {
            return ! is_null($value);
        });

        $product = $this->itemsRepository->updateItem($id, $data);

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $data['image'] = $request->file('image')->store('products', 'public');
            $product = $this->itemsRepository->updateItem($id, $data);
        }

        return $this->successResponse(new ProductResource($product), 'Product successfully updated');
    }

    /**
     * Удалить товар/услугу
     *
     * @param  int  $id  ID товара/услуги
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $product = Product::findOrFail($id);

        if (! $this->canPerformAction('products', 'delete', $product)) {
            return $this->errorResponse('У вас нет прав на удаление этого товара', 403);
        }

        $result = $this->itemsRepository->deleteItem($id);

        if (! $result['success']) {
            return $this->errorResponse($result['message'], 400);
        }

        return $this->successResponse(null, 'Товар/услуга успешно удалена');
    }

    /**
     * Получить историю операций по товару
     *
     * @param  int  $id  ID товара
     * @return JsonResponse
     */
    public function history(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $product = Product::with('unit')->findOrFail($id);

        if (! $this->canPerformAction('products', 'view', $product)) {
            return $this->errorResponse('У вас нет прав на просмотр этого товара', 403);
        }

        $unitShortName = $product->unit ? $product->unit->short_name : '';
        $filter = $request->query('filter', 'all');
        $history = collect();

        if (in_array($filter, ['all', 'income'])) {
            foreach (WhReceiptProduct::where('product_id', $id)->with(['receipt.creator'])->get() as $rp) {
                $r = $rp->receipt;
                if (! $r) {
                    continue;
                }
                $u = $r->creator ?? null;
                $history->push([
                    'source_label' => 'Оприходование',
                    'quantity' => (float) $rp->quantity,
                    'unit_short_name' => $unitShortName,
                    'date' => $r->date,
                    'creator' => $u ? [
                        'id' => (int) $u->id,
                        'name' => trim($u->name.' '.($u->surname ?? '')),
                    ] : null,
                ]);
            }
        }

        if (in_array($filter, ['all', 'expense'])) {
            foreach (WhWriteoffProduct::where('product_id', $id)->with(['writeOff.creator'])->get() as $wp) {
                $w = $wp->writeOff;
                if (! $w) {
                    continue;
                }
                $u = $w->creator ?? null;
                $history->push([
                    'source_label' => 'Списание',
                    'quantity' => -(float) $wp->quantity,
                    'unit_short_name' => $unitShortName,
                    'date' => $w->date,
                    'creator' => $u ? [
                        'id' => (int) $u->id,
                        'name' => trim($u->name.' '.($u->surname ?? '')),
                    ] : null,
                ]);
            }
            foreach (SalesProduct::where('product_id', $id)->with(['sale.creator'])->get() as $sp) {
                $s = $sp->sale;
                if (! $s) {
                    continue;
                }
                $u = $s->creator ?? null;
                $history->push([
                    'source_label' => 'Продажа',
                    'quantity' => -(float) $sp->quantity,
                    'unit_short_name' => $unitShortName,
                    'date' => $s->date,
                    'creator' => $u ? [
                        'id' => (int) $u->id,
                        'name' => trim($u->name.' '.($u->surname ?? '')),
                    ] : null,
                ]);
            }
            foreach (OrderProduct::where('product_id', $id)->with(['order.creator'])->get() as $op) {
                $o = $op->order;
                if (! $o) {
                    continue;
                }
                $u = $o->creator ?? null;
                $history->push([
                    'source_label' => 'Заказ',
                    'quantity' => -(float) $op->quantity,
                    'unit_short_name' => $unitShortName,
                    'date' => $o->date,
                    'creator' => $u ? [
                        'id' => (int) $u->id,
                        'name' => trim($u->name.' '.($u->surname ?? '')),
                    ] : null,
                ]);
            }
        }

        $history = $history->sortByDesc('date')->values()->toArray();

        $warehouseStocks = [];
        foreach (WarehouseStock::where('product_id', $id)->with('warehouse')->get() as $ws) {
            if ($ws->warehouse) {
                $warehouseStocks[] = [
                    'warehouse_name' => $ws->warehouse->name,
                    'quantity' => (float) $ws->quantity,
                    'unit_short_name' => $unitShortName,
                ];
            }
        }

        return $this->successResponse([
            'items' => $history,
            'warehouse_stocks' => $warehouseStocks,
        ]);
    }

    /**
     * Нормализует categoryId для simple workers
     * Для simple workers возвращает null, чтобы фильтрация происходила через getUserCategoryIds
     * который учитывает маппинг из конфига и подкатегории
     *
     * @param  mixed  $categoryId
     * @return int|null
     */
    protected function normalizeCategoryIdForSimpleWorker($categoryId)
    {
        $user = auth('api')->user();
        $isSimpleWorker = $user instanceof User && $user->hasRole(config('simple.worker_role'));

        return $isSimpleWorker ? null : $categoryId;
    }

    /**
     * @return string
     */
    protected function resolveWarehouseStockPolicy(Request $request)
    {
        if (! $request->query('warehouse_id')) {
            return 'all';
        }

        return $request->query('warehouse_stock_policy') === 'all' ? 'all' : 'in_stock';
    }
}
