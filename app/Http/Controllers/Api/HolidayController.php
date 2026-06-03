<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreHolidayRequest;
use App\Http\Requests\UpdateHolidayRequest;
use App\Http\Resources\HolidayReferenceResource;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use App\Repositories\HolidayRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с корпоративными праздниками
 */
/**
 * @group Кадры
 * @subgroup Праздники
 */
class HolidayController extends BaseController
{
    protected HolidayRepository $repository;

    public function __construct(HolidayRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Список праздников компании
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);

        $filters = $this->buildFilters($request);

        // Добавляем company_id в фильтры, если передан
        if ($request->has('company_id')) {
            $filters['company_id'] = $request->input('company_id');
        }

        $items = $this->repository->getItemsWithPagination($userUuid, $perPage, $filters);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $items->items(),
                HolidayReferenceResource::class,
                HolidayResource::class,
                $companyId
            ),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Получить все праздники компании
     *
     * @return JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $filters = $this->buildFilters($request);

        // Добавляем company_id в фильтры, если передан
        if ($request->has('company_id')) {
            $filters['company_id'] = $request->input('company_id');
        }

        $items = $this->repository->getAllItems($userUuid, $filters);
        $companyId = $this->getCurrentCompanyId();
        $useReference = $this->useReferenceContractsForWave1All($companyId);
        $collection = $useReference
            ? HolidayReferenceResource::collection($items)
            : HolidayResource::collection($items);

        return $this->successResponse($collection->resolve());
    }

    /**
     * Получить праздник по ID
     *
     * @param  int  $id  ID праздника
     * @return JsonResponse
     */
    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $holiday = $this->repository->getItemById($id);

            return $this->successResponse(new HolidayResource($holiday));
        } catch (\Exception $e) {
            return $this->errorResponse(__('Праздник не найден'), 404);
        }
    }

    /**
     * Создать праздник
     *
     * @return JsonResponse
     */
    public function store(StoreHolidayRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        $data = [
            'name' => $validatedData['name'],
            'date' => $validatedData['date'],
            'end_date' => $validatedData['end_date'] ?? null,
            'is_recurring' => $validatedData['is_recurring'] ?? true,
            'color' => $validatedData['color'] ?? '#FF5733',
            'icon' => $validatedData['icon'],
            'company_id' => $validatedData['company_id'],
        ];

        $created = $this->repository->createItem($data);
        if (! $created) {
            return $this->errorResponse(__('Ошибка создания праздника'), 400);
        }

        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse(
            $this->wave1SingleResource($created, HolidayReferenceResource::class, HolidayResource::class, $companyId),
            'Праздник создан'
        );
    }

    /**
     * Обновить праздник
     *
     * @param  int  $id  ID праздника
     * @return JsonResponse
     */
    public function update(UpdateHolidayRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        try {
            $holiday = Holiday::findOrFail($id);

            $data = array_filter([
                'name' => $validatedData['name'] ?? null,
                'date' => $validatedData['date'] ?? null,
                'end_date' => array_key_exists('end_date', $validatedData) ? $validatedData['end_date'] : null,
                'is_recurring' => $validatedData['is_recurring'] ?? null,
                'color' => $validatedData['color'] ?? null,
                'icon' => $validatedData['icon'] ?? null,
            ], fn ($value) => $value !== null);

            $updated = $this->repository->updateItem($id, $data);
            if (! $updated) {
                return $this->errorResponse(__('api.transfers.update_failed'), 400);
            }

            $companyId = $this->getCurrentCompanyId();

            return $this->successResponse(
                $this->wave1SingleResource($updated, HolidayReferenceResource::class, HolidayResource::class, $companyId),
                'Праздник обновлен'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(__('Праздник не найден'), 404);
        }
    }

    /**
     * Удалить праздник
     *
     * @param  int  $id  ID праздника
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $holiday = Holiday::findOrFail($id);

            $deleted = $this->repository->deleteItem($id);
            if (! $deleted) {
                return $this->errorResponse(__('api.transfers.delete_failed'), 400);
            }

            return $this->successResponse(null, __('Праздник удален'));
        } catch (\Exception $e) {
            return $this->errorResponse(__('Праздник не найден'), 404);
        }
    }

    /**
     * Построить фильтры из запроса
     */
    protected function buildFilters(Request $request): array
    {
        $filters = [];

        if ($request->has('year')) {
            $filters['year'] = $request->input('year');
        }
        if ($request->has('date_from')) {
            $filters['date_from'] = $request->input('date_from');
        }
        if ($request->has('date_to')) {
            $filters['date_to'] = $request->input('date_to');
        }

        return $filters;
    }
}
