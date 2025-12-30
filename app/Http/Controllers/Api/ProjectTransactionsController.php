<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreProjectTransactionRequest;
use App\Http\Requests\UpdateProjectTransactionRequest;
use App\Models\Project;
use App\Models\ProjectTransaction;
use App\Repositories\ProjectTransactionsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectTransactionsController extends BaseController
{
    protected $repository;

    public function __construct(ProjectTransactionsRepository $repository)
    {
        $this->repository = $repository;
    }

    public function index(Request $request, $projectId): JsonResponse
    {
        try {
            $project = $this->findProjectAndCheckAccess($projectId, 'view');
            if ($project instanceof \Illuminate\Http\JsonResponse) {
                return $project;
            }

            $perPage = (int) $request->get('per_page', 20);
            $page = (int) $request->get('page', 1);

            $result = $this->repository->getItemsWithPagination($projectId, $perPage, $page);

            return $this->paginatedResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении транзакций проекта: ' . $e->getMessage(), 500);
        }
    }

    public function getAll(Request $request, $projectId): JsonResponse
    {
        try {
            $project = $this->findProjectAndCheckAccess($projectId, 'view');
            if ($project instanceof \Illuminate\Http\JsonResponse) {
                return $project;
            }

            $transactions = $this->repository->getAllItems($projectId);

            return response()->json($transactions);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении транзакций проекта: ' . $e->getMessage(), 500);
        }
    }

    public function store(StoreProjectTransactionRequest $request): JsonResponse
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

            $validatedData['user_id'] = auth('api')->id();
            $transaction = $this->repository->createItem($validatedData);

            return response()->json([
                'message' => 'Транзакция успешно создана',
                'item' => $transaction
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при создании транзакции: ' . $e->getMessage(), 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $transaction = $this->repository->findItem($id);

            if (!$transaction) {
                return $this->notFoundResponse('Транзакция не найдена');
            }

            $project = $this->findProjectAndCheckAccess($transaction->project_id, 'view');
            if ($project instanceof \Illuminate\Http\JsonResponse) {
                return $project;
            }

            return response()->json(['item' => $transaction]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении транзакции: ' . $e->getMessage(), 500);
        }
    }

    public function update(UpdateProjectTransactionRequest $request, $id): JsonResponse
    {
        try {
            $transaction = ProjectTransaction::findOrFail($id);

            $project = $this->findProjectAndCheckAccess($transaction->project_id, 'update');
            if ($project instanceof \Illuminate\Http\JsonResponse) {
                return $project;
            }

            $validatedData = $request->validated();
            $transaction = $this->repository->updateItem($id, $validatedData);

            return response()->json([
                'message' => 'Транзакция успешно обновлена',
                'item' => $transaction
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при обновлении транзакции: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $transaction = ProjectTransaction::findOrFail($id);

            $project = $this->findProjectAndCheckAccess($transaction->project_id, 'update');
            if ($project instanceof \Illuminate\Http\JsonResponse) {
                return $project;
            }

            $result = $this->repository->deleteItem($id);

            if (!$result) {
                return $this->notFoundResponse('Транзакция не найдена');
            }

            return response()->json(['message' => 'Транзакция успешно удалена']);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при удалении транзакции: ' . $e->getMessage(), 500);
        }
    }

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
