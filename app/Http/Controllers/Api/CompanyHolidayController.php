<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreCompanyHolidayRequest;
use App\Http\Requests\UpdateCompanyHolidayRequest;
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
     */
    public function index(Request $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 50);
        $filters = $this->buildFilters($request);

        $items = $this->repository->getItemsWithPagination($userId, $perPage, $page, $filters);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все праздники компании
     */
    public function all(Request $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();
        $filters = $this->buildFilters($request);

        $items = $this->repository->getAllItems($userId, $filters);

        return response()->json($items);
    }

    /**
     * Получить праздник по ID
     */
    public function show($id)
    {
        $holiday = \App\Models\CompanyHoliday::findOrFail($id);

        if (! $this->canPerformAction('company_holidays', 'view', $holiday)) {
            return $this->forbiddenResponse('Нет доступа к этому празднику');
        }

        return response()->json(['item' => $holiday]);
    }

    /**
     * Создать праздник
     */
    public function store(StoreCompanyHolidayRequest $request)
    {
        if (! $this->hasPermission('company_holidays_create')) {
            return $this->forbiddenResponse('У вас нет прав на создание праздника');
        }

        $validatedData = $request->validated();
        $holiday = $this->repository->createItem($validatedData);

        return response()->json([
            'item' => $holiday,
            'message' => 'Праздник создан',
        ], 201);
    }

    /**
     * Обновить праздник
     */
    public function update(UpdateCompanyHolidayRequest $request, $id)
    {
        $holiday = \App\Models\CompanyHoliday::findOrFail($id);

        if (! $this->canPerformAction('company_holidays', 'update', $holiday)) {
            return $this->forbiddenResponse('Нет доступа к этому празднику');
        }

        $validatedData = $request->validated();
        $holiday = $this->repository->updateItem($id, $validatedData);

        return response()->json([
            'item' => $holiday,
            'message' => 'Праздник обновлён',
        ]);
    }

    /**
     * Удалить праздник
     */
    public function destroy($id)
    {
        $holiday = \App\Models\CompanyHoliday::findOrFail($id);

        if (! $this->canPerformAction('company_holidays', 'delete', $holiday)) {
            return $this->forbiddenResponse('Нет доступа к этому празднику');
        }

        $this->repository->deleteItem($id);

        return response()->json(['message' => 'Праздник удалён']);
    }

    /**
     * Пакетное удаление праздников
     */
    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $holiday = \App\Models\CompanyHoliday::find($id);

                if ($holiday && $this->canPerformAction('company_holidays', 'delete', $holiday)) {
                    $this->repository->deleteItem($id);
                    $deleted++;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return response()->json([
            'message' => "Удалено праздников: $deleted",
            'deleted' => $deleted,
        ]);
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
