<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LeaveTypeResource;
use App\Repositories\LeaveTypeRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с типами отпусков
 */
class LeaveTypeController extends BaseController
{
    protected $itemsRepository;

    /**
     * Конструктор контроллера
     */
    public function __construct(LeaveTypeRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Получить список типов отпусков с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $items = $this->itemsRepository->getItemsWithPagination($perPage, $page);

        return $this->successResponse([
            'items' => LeaveTypeResource::collection($items->items())->resolve(),
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
     * Получить все типы отпусков
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $items = $this->itemsRepository->getAllItems();

        return $this->successResponse(LeaveTypeResource::collection($items)->resolve());
    }

    /**
     * Создать новый тип отпуска
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'color' => 'nullable|string|max:7',
            'is_penalty' => 'nullable|boolean',
        ]);

        $created = $this->itemsRepository->createItem([
            'name' => $request->name,
            'color' => $request->color,
            'is_penalty' => (bool) $request->boolean('is_penalty'),
        ]);
        if (! $created) {
            return $this->errorResponse('Ошибка создания типа отпуска', 400);
        }

        return $this->successResponse(new LeaveTypeResource($created), 'Тип отпуска создан');
    }

    /**
     * Обновить тип отпуска
     *
     * @param  int  $id  ID типа отпуска
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'name' => 'required|string',
            'color' => 'nullable|string|max:7',
            'is_penalty' => 'nullable|boolean',
        ]);

        $updated = $this->itemsRepository->updateItem($id, [
            'name' => $request->name,
            'color' => $request->color,
            'is_penalty' => (bool) $request->boolean('is_penalty'),
        ]);
        if (! $updated) {
            return $this->errorResponse('Ошибка обновления', 400);
        }

        return $this->successResponse(new LeaveTypeResource($updated), 'Тип отпуска обновлен');
    }

    /**
     * Удалить тип отпуска
     *
     * @param  int  $id  ID типа отпуска
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        try {
            $deleted = $this->itemsRepository->deleteItem($id);
            if (! $deleted) {
                return $this->errorResponse('Ошибка удаления', 400);
            }

            return $this->successResponse(null, 'Тип отпуска удален');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
