<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\TransactionCategoryRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;

class TransactionCategoryController extends Controller
{
    protected $repo;

    public function __construct(TransactionCategoryRepository $repo)
    {
        $this->repo = $repo;
    }

    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $items = $this->repo->getItemsWithPagination(20);

        $mappedItems = collect($items->items())->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'type' => $item->type,
                'user_id' => $item->user_id,
                'user_name' => $item->user ? $item->user->name : null,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        });

        return response()->json([
            'items' => $mappedItems,
            'current_page' => $items->currentPage(),
            'next_page' => $items->nextPageUrl(),
            'last_page' => $items->lastPage(),
            'total' => $items->total()
        ]);
    }

    public function all(Request $request)
    {
        $items = $this->repo->getAllItems();

        return response()->json($items->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'type' => $item->type,
                'user_id' => $item->user_id,
                'user_name' => $item->user ? $item->user->name : null,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        }));
    }

    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'type' => 'required|boolean',
        ]);

        $created = $this->repo->createItem([
            'name' => $request->name,
            'type' => $request->type,
            'user_id' => $userUuid,
        ]);

        if (!$created) return $this->errorResponse('Ошибка создания категории транзакции', 400);
        CacheService::invalidateTransactionCategoriesCache();

        return response()->json(['message' => 'Категория транзакции создана']);
    }

    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'type' => 'required|boolean',
        ]);

        try {
            $updated = $this->repo->updateItem($id, [
                'name' => $request->name,
                'type' => $request->type,
                'user_id' => $userUuid,
            ]);

            if (!$updated) return $this->notFoundResponse('Категория транзакции не найдена');

            CacheService::invalidateTransactionCategoriesCache();

            return response()->json(['message' => 'Категория транзакции обновлена']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $deleted = $this->repo->deleteItem($id);
            if (!$deleted) return $this->notFoundResponse('Категория транзакции не найдена');

            CacheService::invalidateTransactionCategoriesCache();

            return response()->json(['message' => 'Категория транзакции удалена']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
