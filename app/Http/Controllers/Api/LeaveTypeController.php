<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LeaveTypeReferenceResource;
use App\Http\Resources\LeaveTypeResource;
use App\Repositories\LeaveTypeRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с типами отпусков
 */
/**
 * @group Кадры
 * @subgroup Типы отпусков
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
     * Список типов отпусков
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $items = $this->itemsRepository->getItemsWithPagination($perPage, $page);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $items->items(),
                LeaveTypeReferenceResource::class,
                LeaveTypeResource::class,
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
     * Получить все типы отпусков
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $items = $this->itemsRepository->getAllItems();

        $useReference = $this->useReferenceContractsForWave1All($this->getCurrentCompanyId());
        $collection = $useReference
            ? LeaveTypeReferenceResource::collection($items)
            : LeaveTypeResource::collection($items);

        return $this->successResponse($collection->resolve());
    }

    /**
     * Создать тип отпуска
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
            return $this->errorResponse(__('Ошибка создания типа отпуска'), 400);
        }

        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse(
            $this->wave1SingleResource($created, LeaveTypeReferenceResource::class, LeaveTypeResource::class, $companyId),
            'Тип отпуска создан'
        );
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
            return $this->errorResponse(__('api.transfers.update_failed'), 400);
        }

        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse(
            $this->wave1SingleResource($updated, LeaveTypeReferenceResource::class, LeaveTypeResource::class, $companyId),
            'Тип отпуска обновлен'
        );
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
                return $this->errorResponse(__('api.transfers.delete_failed'), 400);
            }

            return $this->successResponse(null, __('Тип отпуска удален'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
