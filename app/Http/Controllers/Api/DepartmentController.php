<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Repositories\DepartmentRepository;
use Illuminate\Http\Request;

class DepartmentController extends BaseController
{
    protected $itemsRepository;

    public function __construct(DepartmentRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Department::class);

        $userId = $this->getAuthenticatedUserIdOrFail();
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);

        $departments = $this->itemsRepository->getItemsWithPagination($userId, $perPage, $page);

        return $this->successResponse([
            'items' => DepartmentResource::collection($departments->items())->resolve(),
            'meta' => [
                'current_page' => $departments->currentPage(),
                'next_page' => $departments->nextPageUrl(),
                'last_page' => $departments->lastPage(),
                'per_page' => $departments->perPage(),
                'total' => $departments->total(),
            ],
        ]);
    }

    public function all(Request $request)
    {
        $this->authorize('viewAny', Department::class);

        $userId = $this->getAuthenticatedUserIdOrFail();
        $departments = $this->itemsRepository->getAllItems($userId);

        return $this->successResponse(DepartmentResource::collection($departments)->resolve());
    }

    public function store(StoreDepartmentRequest $request)
    {
        $this->authorize('create', Department::class);

        $validatedData = $request->validated();
        $department = $this->itemsRepository->createItem($validatedData);

        return $this->successResponse(new DepartmentResource($department), 'Департамент создан');
    }

    public function update(UpdateDepartmentRequest $request, $id)
    {
        $department = Department::findOrFail($id);

        $this->authorize('update', $department);

        $validatedData = $request->validated();
        $department = $this->itemsRepository->updateItem($id, $validatedData);

        return $this->successResponse(new DepartmentResource($department), 'Департамент обновлён');
    }

    public function destroy($id)
    {
        $department = Department::findOrFail($id);

        $this->authorize('delete', $department);

        $this->itemsRepository->deleteItem($id);

        return $this->successResponse(null, 'Департамент удалён');
    }
}
