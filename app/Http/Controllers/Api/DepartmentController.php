<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Repositories\DepartmentRepository;
use Illuminate\Http\Request;

class DepartmentController extends BaseController
{
    protected $departmentRepository;

    public function __construct(DepartmentRepository $departmentRepository)
    {
        $this->departmentRepository = $departmentRepository;
    }

    public function index(Request $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);

        $departments = $this->departmentRepository->getItemsWithPagination($userId, $perPage, $page);

        return $this->paginatedResponse($departments);
    }

    public function all(Request $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();
        $departments = $this->departmentRepository->getAllItems($userId);

        return response()->json($departments);
    }

    public function store(StoreDepartmentRequest $request)
    {
        if (!$this->hasPermission('departments_create')) {
            return $this->forbiddenResponse('У вас нет прав на создание департамента');
        }

        $validatedData = $request->validated();
        $department = $this->departmentRepository->createItem($validatedData);

        return response()->json(['department' => $department, 'message' => 'Департамент создан']);
    }

    public function update(UpdateDepartmentRequest $request, $id)
    {
        $department = \App\Models\Department::findOrFail($id);

        if (!$this->canPerformAction('departments', 'update', $department)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этого департамента');
        }

        $validatedData = $request->validated();
        $department = $this->departmentRepository->updateItem($id, $validatedData);

        return response()->json(['department' => $department, 'message' => 'Департамент обновлён']);
    }

    public function destroy($id)
    {
        $department = \App\Models\Department::findOrFail($id);

        if (!$this->canPerformAction('departments', 'delete', $department)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этого департамента');
        }

        $this->departmentRepository->deleteItem($id);

        return response()->json(['message' => 'Департамент удалён']);
    }
}
