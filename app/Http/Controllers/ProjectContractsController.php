<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ProjectContractsRepository;
use App\Models\ProjectContract;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для управления контрактами проектов
 */
class ProjectContractsController extends Controller
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
            $project = Project::find($projectId);
            if (!$project) {
                return $this->notFoundResponse('Проект не найден');
            }

            if (!$this->canPerformAction('projects', 'view', $project)) {
                return $this->forbiddenResponse('У вас нет прав на просмотр этого проекта');
            }

            $perPage = (int) $request->get('per_page', 20);
            $page = (int) $request->get('page', 1);
            $search = $request->get('search');

            $result = $this->repository->getProjectContractsWithPagination($projectId, $perPage, $page, $search);

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
            $project = Project::find($projectId);
            if (!$project) {
                return $this->notFoundResponse('Проект не найден');
            }

            if (!$this->canPerformAction('projects', 'view', $project)) {
                return $this->forbiddenResponse('У вас нет прав на просмотр этого проекта');
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
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'project_id' => 'required|exists:projects,id',
                'number' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0',
                'currency_id' => 'nullable|exists:currencies,id',
                'date' => 'required|date',
                'returned' => 'nullable|boolean',
                'files' => 'nullable|array',
                'note' => 'nullable|string'
            ]);

            $project = Project::find($request->project_id);
            if (!$project) {
                return $this->notFoundResponse('Проект не найден');
            }

            if (!$this->canPerformAction('projects', 'update', $project)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого проекта');
            }

            $data = $request->only([
                'project_id',
                'number',
                'amount',
                'currency_id',
                'date',
                'returned',
                'files',
                'note'
            ]);

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

            $project = $contract->project;
            if (!$project) {
                return $this->notFoundResponse('Проект не найден');
            }

            if (!$this->canPerformAction('projects', 'view', $project)) {
                return $this->forbiddenResponse('У вас нет прав на просмотр этого проекта');
            }

            return response()->json(['item' => $contract]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении контракта: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Обновление контракта
     *
     * @param Request $request
     * @param int $id ID контракта
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $contract = ProjectContract::find($id);
            if (!$contract) {
                return $this->notFoundResponse('Контракт не найден');
            }

            $project = $contract->project;
            if (!$project) {
                return $this->notFoundResponse('Проект не найден');
            }

            if (!$this->canPerformAction('projects', 'update', $project)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого проекта');
            }

            $request->validate([
                'number' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0',
                'currency_id' => 'nullable|exists:currencies,id',
                'date' => 'required|date',
                'returned' => 'nullable|boolean',
                'files' => 'nullable|array',
                'note' => 'nullable|string'
            ]);

            $data = $request->only([
                'number',
                'amount',
                'currency_id',
                'date',
                'returned',
                'files',
                'note'
            ]);

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
            $contract = ProjectContract::find($id);
            if (!$contract) {
                return $this->notFoundResponse('Контракт не найден');
            }

            $project = $contract->project;
            if (!$project) {
                return $this->notFoundResponse('Проект не найден');
            }

            if (!$this->canPerformAction('projects', 'update', $project)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого проекта');
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
}
