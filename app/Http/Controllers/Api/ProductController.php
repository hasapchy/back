<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesProduct;
use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\WhWaybill;
use App\Models\WhWaybillProduct;
use App\Models\WhWriteoff;
use App\Models\Category;
use App\Models\WhWriteoffProduct;
use App\Repositories\ProductsRepository;
use App\Support\SimpleUser;
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
        $categoryId = $this->normalizeCategoryIdForSimpleUser($request->query('category_id'));
        $categoryIds = $this->normalizeCategoryIdsForSimpleUser($request->query('category_ids'));
        $warehouseStockPolicy = $this->resolveWarehouseStockPolicy($request);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, true, $page, $warehouseId, $search, $categoryId, $warehouseStockPolicy, $categoryIds);

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
        $categoryId = $this->normalizeCategoryIdForSimpleUser($request->query('category_id'));
        $categoryIds = $this->normalizeCategoryIdsForSimpleUser($request->query('category_ids'));
        $warehouseStockPolicy = $this->resolveWarehouseStockPolicy($request);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $result = $this->itemsRepository->searchItems($userUuid, $search, $productsOnly, $warehouseId, $categoryId, $warehouseStockPolicy, $page, $perPage, $categoryIds);

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
        $this->authorize('view', $product);

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
        $categoryId = $this->normalizeCategoryIdForSimpleUser($request->query('category_id'));
        $categoryIds = $this->normalizeCategoryIdsForSimpleUser($request->query('category_ids'));
        $warehouseStockPolicy = $this->resolveWarehouseStockPolicy($request);

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $per_page, false, $page, $warehouseId, $search, $categoryId, $warehouseStockPolicy, $categoryIds);

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

        Product::findOrFail($id);

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
        $this->authorize('delete', $product);

        $result = $this->itemsRepository->deleteItem($id);

        if (! $result['success']) {
            return $this->errorResponse($result['message'], 400);
        }

        return $this->successResponse(null, 'Товар/услуга успешно удалена');
    }

    /**
     * @param  Request  $request  filter=all|income|expense
     * @param  int|string  $id
     * @return JsonResponse
     */
    public function history(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $product = Product::with('unit')->findOrFail($id);
        $this->authorize('view', $product);

        $unitShortName = $product->unit ? $product->unit->short_name : '';
        $filter = $request->query('filter', 'all');
        $history = collect();

        if (in_array($filter, ['all', 'income'], true)) {
            foreach (WhWaybillProduct::query()
                ->where('product_id', $id)
                ->with(['waybill.creator', 'waybill.receipt'])
                ->get() as $wbp) {
                $waybill = $wbp->waybill;
                if (! $waybill) {
                    continue;
                }
                $receipt = $waybill->receipt;
                $u = $waybill->creator ?? null;
                $history->push([
                    'source_label' => (string) __('product_history.waybill_receipt'),
                    'source_type' => WhWaybill::class,
                    'source_id' => (int) $waybill->id,
                    'receipt_id' => $receipt ? (int) $receipt->id : null,
                    'quantity' => (float) $wbp->quantity,
                    'unit_short_name' => $unitShortName,
                    'date' => $waybill->date,
                    'creator' => $u ? [
                        'id' => (int) $u->id,
                        'name' => trim($u->name.' '.($u->surname ?? '')),
                    ] : null,
                ]);
            }

            foreach (WhReceiptProduct::where('product_id', $id)->with(['receipt.creator'])->get() as $rp) {
                $r = $rp->receipt;
                if (! $r || ! $r->is_legacy) {
                    continue;
                }
                $u = $r->creator ?? null;
                $history->push([
                    'source_label' => (string) __('product_history.receipt_direct_stock'),
                    'source_type' => WhReceipt::class,
                    'source_id' => (int) $r->id,
                    'receipt_id' => null,
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
                    'source_type' => WhWriteoff::class,
                    'source_id' => (int) $w->id,
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
                    'source_type' => Sale::class,
                    'source_id' => (int) $s->id,
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
                    'source_type' => Order::class,
                    'source_id' => (int) $o->id,
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

    protected function normalizeCategoryIdForSimpleUser($categoryId)
    {
        $user = auth('api')->user();
        if (! SimpleUser::matches($user)) {
            return $categoryId;
        }

        $rootId = SimpleUser::rootCategoryIdForCurrentCompany($user);
        if ($rootId === null || $categoryId === null || $categoryId === '') {
            return null;
        }

        $cid = (int) $categoryId;

        return in_array($cid, Category::descendantIdsIncludingRoot($rootId), true) ? $cid : null;
    }

    /**
     * @param  mixed  $categoryIds
     * @return int[]
     */
    protected function normalizeCategoryIdsForSimpleUser($categoryIds): array
    {
        if ($categoryIds === null || $categoryIds === '') {
            return [];
        }

        if (is_string($categoryIds)) {
            $categoryIds = array_filter(array_map('trim', explode(',', $categoryIds)));
        }

        $normalized = array_values(array_unique(array_filter(array_map('intval', (array) $categoryIds))));

        if (empty($normalized)) {
            return [];
        }

        $user = auth('api')->user();
        if (! SimpleUser::matches($user)) {
            return $normalized;
        }

        $rootId = SimpleUser::rootCategoryIdForCurrentCompany($user);
        if ($rootId === null) {
            return [];
        }

        $allowed = Category::descendantIdsIncludingRoot($rootId);
        return array_values(array_intersect($normalized, $allowed));
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
