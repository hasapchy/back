<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentReferenceResource;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Repositories\DepartmentRepository;
use Illuminate\Http\Request;

/**
 * @group Кадры
 * @subgroup Отделы
 */
class DepartmentController extends BaseController
{
    protected $itemsRepository;

    public function __construct(DepartmentRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    /**
     * Список отделов
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Department::class);

        $userId = $this->getAuthenticatedUserIdOrFail();
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);

        $departments = $this->itemsRepository->getItemsWithPagination($userId, $perPage, $page);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse([
            'items' => $this->wave1IndexCollection(
                $departments->items(),
                DepartmentReferenceResource::class,
                DepartmentResource::class,
                $companyId
            ),
            'meta' => [
                'current_page' => $departments->currentPage(),
                'next_page' => $departments->nextPageUrl(),
                'last_page' => $departments->lastPage(),
                'per_page' => $departments->perPage(),
                'total' => $departments->total(),
            ],
        ]);
    }

    /**
     * Все отделы
     */
    public function all(Request $request)
    {
        $this->authorize('viewAny', Department::class);

        $userId = $this->getAuthenticatedUserIdOrFail();
        $departments = $this->itemsRepository->getAllItems($userId);
        $companyId = $this->getCurrentCompanyId();
        $useReference = $this->useReferenceContractsForWave1All($companyId);
        $collection = $useReference
            ? DepartmentReferenceResource::collection($departments)
            : DepartmentResource::collection($departments);

        return $this->successResponse($collection->resolve());
    }

    /**
     * Создать отдел
     */
    public function store(StoreDepartmentRequest $request)
    {
        $this->authorize('create', Department::class);

        $validatedData = $request->validated();
        $department = $this->itemsRepository->createItem($validatedData);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse(
            $this->wave1SingleResource($department, DepartmentReferenceResource::class, DepartmentResource::class, $companyId),
            'Департамент создан'
        );
    }

    /**
     * Обновить отдел
     */
    public function update(UpdateDepartmentRequest $request, $id)
    {
        $department = Department::findOrFail($id);

        $this->authorize('update', $department);

        $validatedData = $request->validated();
        $department = $this->itemsRepository->updateItem($id, $validatedData);
        $companyId = $this->getCurrentCompanyId();

        return $this->successResponse(
            $this->wave1SingleResource($department, DepartmentReferenceResource::class, DepartmentResource::class, $companyId),
            'Департамент обновлён'
        );
    }

    /**
     * Удалить отдел
     */
    public function destroy($id)
    {
        $department = Department::findOrFail($id);

        $this->authorize('delete', $department);

        $this->itemsRepository->deleteItem($id);

        return $this->successResponse(null, 'Департамент удалён');
    }
}
