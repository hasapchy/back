<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Repositories\CompanyHolidayRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с корпоративными праздниками
 */
class CompanyHolidayController extends BaseController
{
    protected CompanyHolidayRepository $repository;

    /**
     * Конструктор контроллера
     *
     * @param CompanyHolidayRepository $repository
     */
    public function __construct(CompanyHolidayRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Получить список праздников компании с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $companyId = $user->company_id;
        $perPage = $request->input('per_page', 50);
        
        $filters = $this->buildFilters($request);

        $items = $this->repository->getItemsWithPagination($companyId, $perPage, $filters);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все праздники компании
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $companyId = $user->company_id;
        
        $filters = $this->buildFilters($request);

        $items = $this->repository->getAllItems($companyId, $filters);

        return response()->json($items);
    }

    /**
     * Получить праздник по ID
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        try {
            $holiday = $this->repository->getItemById($id);
            
            if ($holiday->company_id !== $user->company_id) {
                return $this->forbiddenResponse('Нет доступа к этому празднику');
            }
            
            return response()->json(['item' => $holiday]);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Праздник не найден');
        }
    }

    /**
     * Создать праздник
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'is_recurring' => 'nullable|boolean',
            'color' => 'nullable|string|max:7',
        ]);

        $data = [
            'company_id' => $user->company_id,
            'name' => $request->name,
            'date' => $request->date,
            'is_recurring' => $request->input('is_recurring', true),
            'color' => $request->input('color', '#FF5733'),
        ];

        $created = $this->repository->createItem($data);

        return response()->json([
            'item' => $created,
            'message' => 'Праздник создан'
        ], 201);
    }

    /**
     * Обновить праздник
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $request->validate([
            'name' => 'nullable|string|max:255',
            'date' => 'nullable|date',
            'is_recurring' => 'nullable|boolean',
            'color' => 'nullable|string|max:7',
        ]);

        try {
            $holiday = $this->repository->getItemById($id);
            
            if ($holiday->company_id !== $user->company_id) {
                return $this->forbiddenResponse('Нет доступа к этому празднику');
            }

            $data = array_filter([
                'name' => $request->input('name'),
                'date' => $request->input('date'),
                'is_recurring' => $request->has('is_recurring') ? $request->input('is_recurring') : null,
                'color' => $request->input('color'),
            ], fn($value) => $value !== null);

            $updated = $this->repository->updateItem($id, $data);

            return response()->json([
                'item' => $updated,
                'message' => 'Праздник обновлен'
            ]);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Праздник не найден');
        }
    }

    /**
     * Удалить праздник
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        try {
            $holiday = $this->repository->getItemById($id);
            
            if ($holiday->company_id !== $user->company_id) {
                return $this->forbiddenResponse('Нет доступа к этому празднику');
            }

            $this->repository->deleteItem($id);

            return response()->json(['message' => 'Праздник удален']);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Праздник не найден');
        }
    }

    /**
     * Пакетное удаление праздников
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer'
        ]);

        $ids = $request->input('ids');
        $companyId = $user->company_id;

        try {
            $deleted = 0;
            foreach ($ids as $id) {
                $holiday = $this->repository->getItemById($id);
                
                if ($holiday->company_id === $companyId) {
                    $this->repository->deleteItem($id);
                    $deleted++;
                }
            }

            return response()->json([
                'message' => "Удалено праздников: $deleted",
                'deleted' => $deleted
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при удалении праздников: ' . $e->getMessage());
        }
    }

    /**
     * Построить фильтры из запроса
     *
     * @param Request $request
     * @return array
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
