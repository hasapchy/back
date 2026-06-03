<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreTransactionCategoryRequest;
use App\Http\Requests\UpdateTransactionCategoryRequest;
use App\Http\Resources\TransactionCategoryReferenceResource;
use App\Http\Resources\TransactionCategoryResource;
use App\Repositories\TransactionCategoryRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с категориями транзакций
 *
 * @group Финансы
 * @subgroup Категории транзакций
 */
class TransactionCategoryController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     *
     * @param TransactionCategoryRepository $itemsRepository
     */
    public function __construct(TransactionCategoryRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Список категорий транзакций
     *
     * @param Request $request
     * @response 200 {"data":{"items":[],"meta":{"current_page":1,"next_page":null,"last_page":1,"per_page":20,"total":0}}}
     * @response 401 {"error":"Unauthenticated."}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);

        $items = $this->itemsRepository->getItemsWithPagination($perPage, $page);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $items->items(),
                TransactionCategoryReferenceResource::class,
                TransactionCategoryResource::class,
                $companyId
            ),
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
     * Получить все категории транзакций
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all()
    {
        $items = $this->itemsRepository->getAllItems();

        $useReference = $this->useReferenceContractsForWave1All(null);
        $collection = $useReference
            ? TransactionCategoryReferenceResource::collection($items)
            : TransactionCategoryResource::collection($items);

        return $this->successResponse($collection->resolve());
    }

    /**
     * Создать категорию транзакций
     *
     * @param StoreTransactionCategoryRequest $request
     * @response 200 {"data":null,"message":"Категория транзакции создана"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 422 {"error":"The given data was invalid.","errors":{"name":["The name field is required."]}}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreTransactionCategoryRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        $this->itemsRepository->createItem([
            'name' => $validatedData['name'],
            'type' => $validatedData['type'],
            'creator_id' => $userUuid,
            'parent_id' => $validatedData['parent_id'],
        ]);

        return $this->successResponse(null, __('api.transaction_categories.created'));
    }

    /**
     * Изменить категорию транзакций
     *
     * @param UpdateTransactionCategoryRequest $request
     * @param int $id ID категории
     * @response 200 {"data":null,"message":"Категория транзакции обновлена"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Not found"}
     * @response 422 {"error":"The given data was invalid.","errors":{"name":["The name field is required."]}}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateTransactionCategoryRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $validatedData = $request->validated();

        try {
            $payload = [
                'name' => $validatedData['name'],
                'type' => $validatedData['type'],
                'creator_id' => $userUuid,
            ];
            if (array_key_exists('parent_id', $validatedData)) {
                $payload['parent_id'] = $validatedData['parent_id'];
            }
            $this->itemsRepository->updateItem($id, $payload);

            return $this->successResponse(null, __('api.transaction_categories.updated'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Удалить категорию транзакций
     *
     * @param int $id ID категории
     * @response 200 {"data":null,"message":"Категория транзакции удалена"}
     * @response 401 {"error":"Unauthenticated."}
     * @response 404 {"error":"Not found"}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        try {
            $this->itemsRepository->deleteItem($id);

            return $this->successResponse(null, __('api.transaction_categories.deleted'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
