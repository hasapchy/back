<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreCompanyHolidayRequest;
use App\Http\Requests\UpdateCompanyHolidayRequest;
use App\Models\CompanyHoliday;
use App\Repositories\CompanyHolidayRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с корпоративными праздниками
 */
class CompanyHolidayController extends BaseController
{
    protected CompanyHolidayRepository $repository;

    public function __construct(CompanyHolidayRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Получить список праздников компании с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);

        $filters = $this->buildFilters($request);

        $items = $this->repository->getItemsWithPagination($userUuid, $perPage, $filters);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все праздники компании
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $filters = $this->buildFilters($request);

        $items = $this->repository->getAllItems($userUuid, $filters);

        return response()->json($items);
    }

    /**
     * Получить праздник по ID
     *
     * @param  int  $id  ID праздника
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $holiday = $this->repository->getItemById($id);

            return response()->json(['item' => $holiday]);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Праздник не найден');
        }
    }

    /**
     * Создать новый праздник
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreCompanyHolidayRequest $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        $data = [
            'name' => $validatedData['name'],
            'date' => $validatedData['date'],
            'is_recurring' => $validatedData['is_recurring'] ?? true,
            'color' => $validatedData['color'] ?? '#FF5733',
        ];

        $created = $this->repository->createItem($data);
        if (! $created) {
            return $this->errorResponse('Ошибка создания праздника', 400);
        }

        return response()->json(['item' => $created, 'message' => 'Праздник создан']);
    }

    /**
     * Обновить праздник
     *
     * @param  int  $id  ID праздника
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateCompanyHolidayRequest $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validatedData = $request->validated();

        try {
            $holiday = CompanyHoliday::findOrFail($id);

            $data = array_filter([
                'name' => $request->input('name'),
                'date' => $request->input('date'),
                'is_recurring' => $request->has('is_recurring') ? $request->input('is_recurring') : null,
                'color' => $request->input('color'),
            ], fn ($value) => $value !== null);

            $updated = $this->repository->updateItem($id, $data);
            if (! $updated) {
                return $this->errorResponse('Ошибка обновления', 400);
            }

            return response()->json(['item' => $updated, 'message' => 'Праздник обновлен']);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Праздник не найден');
        }
    }

    /**
     * Удалить праздник
     *
     * @param  int  $id  ID праздника
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $holiday = CompanyHoliday::findOrFail($id);

            $deleted = $this->repository->deleteItem($id);
            if (! $deleted) {
                return $this->errorResponse('Ошибка удаления', 400);
            }

            return response()->json(['message' => 'Праздник удален']);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Праздник не найден');
        }
    }

    /**
     * Пакетное удаление праздников
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');

        try {
            $deleted = 0;
            foreach ($ids as $id) {
                try {
                    $this->repository->deleteItem($id);
                    $deleted++;
                } catch (\Exception $e) {
                    // Пропускаем записи, которые не найдены
                    continue;
                }
            }

            return response()->json([
                'message' => "Удалено праздников: $deleted",
                'deleted' => $deleted,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при пакетном удалении', 400);
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
