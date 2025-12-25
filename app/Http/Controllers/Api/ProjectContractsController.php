<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreProjectContractRequest;
use App\Http\Requests\UpdateProjectContractRequest;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Repositories\ProjectContractsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для управления контрактами проектов
 */
class ProjectContractsController extends BaseController
{
    /**
     * @var ProjectContractsRepository
     */
    protected $repository;

    /**
     * @param ProjectContractsRepository $repository
     */
    public function __construct(ProjectContractsRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Получение контрактов проекта с пагинацией
     *
     * @param Request $request
     * @param int $projectId ID проекта
     * @return JsonResponse
     */
    public function index(Request $request, $projectId): JsonResponse
    {
        try {
            $project = $this->findProjectAndCheckAccess($projectId, 'view');
            if ($project instanceof \Illuminate\Http\JsonResponse) {
                return $project;
            }

            $perPage = (int) $request->get('per_page', 20);
            $page = (int) $request->get('page', 1);
            $search = $request->get('search');

            $result = $this->repository->getItemsWithPagination($projectId, $perPage, $page, $search);

            return $this->paginatedResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении контрактов проекта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получение всех контрактов проекта
     *
     * @param Request $request
     * @param int $projectId ID проекта
     * @return JsonResponse
     */
    public function getAll(Request $request, $projectId): JsonResponse
    {
        try {
            $project = $this->findProjectAndCheckAccess($projectId, 'view');
            if ($project instanceof \Illuminate\Http\JsonResponse) {
                return $project;
            }

            $contracts = $this->repository->getAllItems($projectId);

            return response()->json($contracts);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении контрактов проекта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Создание нового контракта
     *
     * @param StoreProjectContractRequest $request
     * @return JsonResponse
     */
    public function store(StoreProjectContractRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            $project = Project::find($validatedData['project_id']);
            if (!$project) {
                return $this->notFoundResponse('Проект не найден');
            }

            if (!$this->canPerformAction('projects', 'update', $project)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого проекта');
            }

            $data = $validatedData;

            $contract = $this->repository->createContract($data);

            return response()->json([
                'message' => 'Контракт успешно создан',
                'item' => $contract->load(['currency', 'project'])
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при создании контракта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получение контракта по ID
     *
     * @param int $id ID контракта
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        try {
            $contract = $this->repository->findContract($id);

            if (!$contract) {
                return $this->notFoundResponse('Контракт не найден');
            }

            $project = $this->findProjectAndCheckAccess($contract->project_id, 'view');
            if ($project instanceof \Illuminate\Http\JsonResponse) {
                return $project;
            }

            return response()->json(['item' => $contract]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении контракта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Обновление контракта
     *
     * @param UpdateProjectContractRequest $request
     * @param int $id ID контракта
     * @return JsonResponse
     */
    public function update(UpdateProjectContractRequest $request, $id): JsonResponse
    {
        try {
            $contract = ProjectContract::findOrFail($id);

            $project = $this->findProjectAndCheckAccess($contract->project_id, 'update');
            if ($project instanceof \Illuminate\Http\JsonResponse) {
                return $project;
            }

            $validatedData = $request->validated();

            $data = $validatedData;

            $contract = $this->repository->updateContract($id, $data);

            return response()->json([
                'message' => 'Контракт успешно обновлен',
                'item' => $contract->load(['currency', 'project'])
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при обновлении контракта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Удаление контракта
     *
     * @param int $id ID контракта
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        try {
            $contract = ProjectContract::findOrFail($id);

            $project = $this->findProjectAndCheckAccess($contract->project_id, 'update');
            if ($project instanceof \Illuminate\Http\JsonResponse) {
                return $project;
            }

            $result = $this->repository->deleteContract($id);

            if (!$result) {
                return $this->notFoundResponse('Контракт не найден');
            }

            return response()->json(['message' => 'Контракт успешно удален']);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при удалении контракта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Найти проект и проверить доступ
     *
     * @param int $projectId ID проекта
     * @param string $action Действие (view, update, delete)
     * @return \App\Models\Project|\Illuminate\Http\JsonResponse
     */
    protected function findProjectAndCheckAccess(int $projectId, string $action)
    {
        $project = Project::find($projectId);
        if (!$project) {
            return $this->notFoundResponse('Проект не найден');
        }

        $actionMessages = [
            'view' => 'У вас нет прав на просмотр этого проекта',
            'update' => 'У вас нет прав на редактирование этого проекта',
            'delete' => 'У вас нет прав на удаление этого проекта',
        ];

        if (!$this->canPerformAction('projects', $action, $project)) {
            return $this->forbiddenResponse($actionMessages[$action] ?? 'У вас нет прав на это действие');
        }

        return $project;
    }
}

