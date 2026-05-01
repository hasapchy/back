<?php

namespace App\Http\Controllers\Api;

use App\Exports\GenericExport;
use App\Http\Requests\StoreInventoryRequest;
use App\Http\Requests\UpdateInventoryItemsRequest;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Repositories\InventoryRepository;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryController extends BaseController
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly InventoryRepository $inventoryRepository,
    ) {
    }

    public function store(StoreInventoryRequest $request): JsonResponse
    {
        try {
            $inventory = $this->inventoryService->createInventory(
                $request->validated(),
                $this->getAuthenticatedUserIdOrFail()
            );

            return $this->successResponse(InventoryResource::make($inventory)->resolve(), 'Инвентаризация создана');
        } catch (\Throwable $e) {
            return $this->inventoryMutationJsonResponse($e);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->inventoryRepository->getItemsPaginated(
            (int) $request->input('per_page', 20),
            (int) $request->input('page', 1)
        );

        return $this->successResponse([
            'items' => InventoryResource::collection($items->items())->resolve(),
            'meta' => $this->paginationMetaFromPaginator($items),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->withInventory($id, fn (Inventory $inventory) => $this->successResponse(
            InventoryResource::make($inventory)->resolve()
        ));
    }

    public function items(int $id, Request $request): JsonResponse
    {
        return $this->withInventory($id, function (Inventory $inventory) use ($request) {
            $paginator = $this->orderedInventoryItems($inventory)->paginate(
                (int) $request->input('per_page', 50),
                ['*'],
                'page',
                (int) $request->input('page', 1)
            );

            return $this->successResponse([
                'items' => InventoryItemResource::collection($paginator->items())->resolve(),
                'meta' => $this->paginationMetaFromPaginator($paginator),
            ]);
        });
    }

    public function updateItems(int $id, UpdateInventoryItemsRequest $request): JsonResponse
    {
        return $this->withInventory($id, function (Inventory $inventory) use ($request) {
            try {
                $this->inventoryService->bulkUpdateItems($inventory, $request->validated()['items']);

                return $this->successResponse(null, 'Позиции обновлены');
            } catch (\Throwable $e) {
                return $this->inventoryMutationJsonResponse($e);
            }
        });
    }

    public function finalize(int $id): JsonResponse
    {
        return $this->withInventory($id, function (Inventory $inventory) {
            try {
                $finalized = $this->inventoryService->finalizeInventory(
                    $inventory,
                    $this->getAuthenticatedUserIdOrFail()
                );

                return $this->successResponse(InventoryResource::make($finalized)->resolve(), 'Инвентаризация завершена');
            } catch (\Throwable $e) {
                return $this->inventoryMutationJsonResponse($e);
            }
        });
    }

    public function destroy(int $id): JsonResponse
    {
        return $this->withInventory($id, function (Inventory $inventory) {
            try {
                $this->inventoryService->deleteInventory($inventory);

                return $this->successResponse(null, 'Инвентаризация удалена');
            } catch (\Throwable $e) {
                return $this->inventoryMutationJsonResponse($e);
            }
        });
    }

    /**
     * Применить к складу недостачу (списание) и/или излишек (оприходование) по завершённой инвентаризации.
     *
     * @return JsonResponse
     */
    public function applyInventoryStockAdjustment(int $id): JsonResponse
    {
        return $this->withInventory($id, function (Inventory $inventory) {
            try {
                $updated = $this->inventoryService->applyStockAdjustments($inventory);

                return $this->successResponse(InventoryResource::make($updated)->resolve(), 'Склад обновлён по результатам инвентаризации');
            } catch (QueryException $e) {
                report($e);

                return $this->errorResponse('Списание не создано: ошибка при сохранении в базу', 422);
            } catch (\Throwable $e) {
                return $this->inventoryMutationJsonResponse($e);
            }
        });
    }

    public function report(int $id, Request $request): JsonResponse
    {
        return $this->withInventory($id, function (Inventory $inventory) use ($request) {
            $sort = (string) $request->query('sort', 'category');

            return $this->successResponse([
                'inventory' => InventoryResource::make($inventory)->resolve(),
                'items' => InventoryItemResource::collection(
                    $this->orderedInventoryItems($inventory, $sort)->get()
                )->resolve(),
            ]);
        });
    }

    public function export(int $id, Request $request): BinaryFileResponse
    {
        return $this->withInventoryBinary($id, function (Inventory $inventory) use ($request) {
            $sort = (string) $request->query('sort', 'category');
            $items = $this->orderedInventoryItems($inventory, $sort)->get();

            return Excel::download(
                new GenericExport(
                    $items->map(fn ($item) => [
                        $item->product_name,
                        $item->category_name,
                        $item->unit_short_name,
                        (float) $item->expected_quantity,
                        $item->actual_quantity !== null ? (float) $item->actual_quantity : null,
                        (float) $item->difference_quantity,
                        $item->difference_type,
                    ])->all(),
                    ['Товар', 'Категория', 'Ед.', 'Учет', 'Факт', 'Разница', 'Тип']
                ),
                'inventory_'.$inventory->id.'_'.date('Y-m-d_His').'.xlsx',
                \Maatwebsite\Excel\Excel::XLSX
            );
        });
    }

    /**
     * @param  callable(Inventory):(JsonResponse)  $callback
     */
    private function withInventory(int $id, callable $callback): JsonResponse
    {
        $inventory = $this->inventoryRepository->getByIdForUser($id);
        if (! $inventory) {
            return $this->errorResponse('Инвентаризация не найдена', 404);
        }

        return $callback($inventory);
    }

    /**
     * @param  callable(Inventory): BinaryFileResponse  $callback
     */
    private function withInventoryBinary(int $id, callable $callback): BinaryFileResponse
    {
        $inventory = $this->inventoryRepository->getByIdForUser($id);
        abort_if(! $inventory, 404, 'Инвентаризация не найдена');

        return $callback($inventory);
    }

    /**
     * @param  LengthAwarePaginator<Inventory>|LengthAwarePaginator<InventoryItem>  $items
     *
     * @return array<string, mixed>
     */
    private function paginationMetaFromPaginator(LengthAwarePaginator $items): array
    {
        return [
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
        ];
    }

    /**
     * @return HasMany<InventoryItem>
     */
    private function orderedInventoryItems(Inventory $inventory, string $sort = 'category'): HasMany
    {
        $query = $inventory->items();

        return $sort === 'diff_desc'
            ? tap($query, fn ($q) => $q->orderByDesc('difference_quantity'))
            : tap($query, fn ($q) => $q->orderBy('category_name')->orderBy('product_name'));
    }

    /**
     * @param  \Throwable  $exception
     * @return JsonResponse
     */
    private function inventoryMutationJsonResponse(\Throwable $exception): JsonResponse
    {
        $message = $exception->getMessage();
        if ($message === 'INVENTORY_IMMUTABLE') {
            return $this->errorResponse($message, 403);
        }

        return $this->errorResponse($message, 400);
    }
}

