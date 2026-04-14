<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\PatchProjectContractRequest;
use App\Http\Requests\StoreProjectContractRequest;
use App\Http\Resources\ProjectContractResource;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Repositories\ProjectContractsRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Контроллер для управления контрактами проектов
 */
class ProjectContractsController extends BaseController
{
    /**
     * @var ProjectContractsRepository
     */
    protected $repository;

    public function __construct(ProjectContractsRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Получение контрактов проекта с пагинацией
     *
     * @param  int  $projectId  ID проекта
     */
    public function index(Request $request, $projectId): JsonResponse
    {
        try {
            $project = $this->findProjectAndCheckAccess($projectId, 'view');
            if ($project instanceof JsonResponse) {
                return $project;
            }

            $perPage = (int) $request->get('per_page', 20);
            $page = (int) $request->get('page', 1);
            $search = $request->get('search');

            $result = $this->repository->getItemsWithPagination($projectId, $perPage, $page, $search);

            return $this->successResponse([
                'items' => ProjectContractResource::collection($result->items())->resolve(),
                'meta' => [
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении контрактов проекта: '.$e->getMessage(), 500);
        }
    }

    /**
     * Получение всех контрактов проекта
     *
     * @param  int  $projectId  ID проекта
     */
    public function getAll(Request $request, $projectId): JsonResponse
    {
        try {
            $project = $this->findProjectAndCheckAccess($projectId, 'view');
            if ($project instanceof JsonResponse) {
                return $project;
            }

            $contracts = $this->repository->getAllItems($projectId);

            return $this->successResponse(ProjectContractResource::collection($contracts)->resolve());
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении контрактов проекта: '.$e->getMessage(), 500);
        }
    }

    /**
     * Получение всех контрактов с пагинацией (без фильтра по проекту)
     */
    public function getAllContracts(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', ProjectContract::class);

            $perPage = (int) $request->get('per_page', 20);
            $page = (int) $request->get('page', 1);
            $search = $request->get('search');
            $projectId = $request->get('project_id');
            $projectStatusId = $request->get('project_status_id') ? (int) $request->get('project_status_id') : null;
            $activeProjectsOnly = $request->boolean('active_projects_only');

            $v = $request->get('payment_status');
            $paymentStatus = ($v !== null && $v !== '' && in_array($v, ['unpaid', 'partially_paid', 'paid'], true)) ? $v : null;
            $returned = $request->has('returned') ? $request->boolean('returned') : null;
            $cashId = $request->get('cash_id') ? (int) $request->get('cash_id') : null;
            $type = $request->has('type') ? (int) $request->get('type') : null;

            $user = $this->getAuthenticatedUser();
            $hasViewAll = $user && ($user->is_admin || $user->can('contracts_view_all'));

            if (! $hasViewAll && $user && $user->can('contracts_view_own')) {
                $result = $this->repository->getAllContractsWithPaginationForUser($perPage, $page, $search, $projectId, $user->id, $paymentStatus, $returned, $cashId, $type, $activeProjectsOnly, $projectStatusId);
            } else {
                $result = $this->repository->getAllContractsWithPagination($perPage, $page, $search, $projectId, $paymentStatus, $returned, $cashId, $type, $activeProjectsOnly, $projectStatusId);
            }

            return $this->successResponse([
                'items' => ProjectContractResource::collection($result->items())->resolve(),
                'meta' => [
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении контрактов: '.$e->getMessage(), 500);
        }
    }

    /**
     * Создание нового контракта
     */
    public function store(StoreProjectContractRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            $project = Project::find($validatedData['project_id']);
            if (! $project) {
                return $this->errorResponse('Проект не найден', 404);
            }

            $this->authorize('update', $project);

            $this->authorize('create', ProjectContract::class);

            $data = $validatedData;

            $contract = $this->repository->createContract($data);

            return $this->successResponse([
                'message' => 'Контракт успешно создан',
                'item' => (new ProjectContractResource($contract->load(['currency', 'project.client', 'cashRegister'])))->resolve(),
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при создании контракта: '.$e->getMessage(), 500);
        }
    }

    /**
     * Получение контракта по ID
     *
     * @param  int  $id  ID контракта
     */
    public function show($id): JsonResponse
    {
        try {
            $contract = $this->repository->findContract($id);

            if (! $contract) {
                return $this->errorResponse('Контракт не найден', 404);
            }

            $this->authorize('view', $contract);

            return $this->successResponse(new ProjectContractResource($contract));
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении контракта: '.$e->getMessage(), 500);
        }
    }

    public function patch(PatchProjectContractRequest $request, $id): JsonResponse
    {
        try {
            $contract = ProjectContract::findOrFail($id);

            $this->authorize('update', $contract);

            $validatedData = $request->validated();

            $contract = $this->repository->updateContract($id, $validatedData);

            return $this->successResponse([
                'message' => 'Контракт успешно обновлен',
                'item' => (new ProjectContractResource($contract->load(['currency', 'project.client', 'cashRegister'])))->resolve(),
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при обновлении контракта: '.$e->getMessage(), 500);
        }
    }

    /**
     * Удаление контракта
     *
     * @param  int  $id  ID контракта
     */
    public function destroy($id): JsonResponse
    {
        try {
            $contract = ProjectContract::findOrFail($id);

            $this->authorize('delete', $contract);

            $result = $this->repository->deleteContract($id);

            if (! $result) {
                return $this->errorResponse('Контракт не найден', 404);
            }

            return $this->successResponse(null, 'Контракт успешно удален');
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при удалении контракта: '.$e->getMessage(), 500);
        }
    }

    /**
     * Найти проект и проверить доступ
     *
     * @param  int  $projectId  ID проекта
     * @param  string  $action  Действие (view, update, delete)
     * @return Project|JsonResponse
     */
    protected function findProjectAndCheckAccess(int $projectId, string $action)
    {
        $project = Project::find($projectId);
        if (! $project) {
            return $this->errorResponse('Проект не найден', 404);
        }

        $actionMessages = [
            'view' => 'У вас нет прав на просмотр этого проекта',
            'update' => 'У вас нет прав на редактирование этого проекта',
            'delete' => 'У вас нет прав на удаление этого проекта',
        ];

        $user = $this->requireAuthenticatedUser();
        if (! $user->can($action, $project)) {
            return $this->errorResponse($actionMessages[$action] ?? 'У вас нет прав на это действие', 403);
        }

        return $project;
    }
}
