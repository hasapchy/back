<?php

namespace App\Http\Controllers;

use App\Repositories\ProjectContractsRepository;
use App\Models\ProjectContract;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProjectContractsController extends Controller
{
    protected $repository;

    public function __construct(ProjectContractsRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Получение контрактов проекта с пагинацией
     */
    public function index(Request $request, $projectId): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            $search = $request->get('search');

            $result = $this->repository->getProjectContractsWithPagination($projectId, $perPage, $page, $search);

            return response()->json([
                'success' => true,
                'data' => [
                    'items' => $result->items(),
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении контрактов проекта: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получение всех контрактов проекта
     */
    public function getAll(Request $request, $projectId): JsonResponse
    {
        try {
            $contracts = $this->repository->getAllProjectContracts($projectId);

            return response()->json([
                'success' => true,
                'data' => $contracts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении контрактов проекта: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создание нового контракта
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
                'returned' => 'boolean',
                'files' => 'nullable|array'
            ]);

            $data = $request->only([
                'project_id',
                'number',
                'amount',
                'currency_id',
                'date',
                'returned',
                'files'
            ]);

            $contract = $this->repository->createContract($data);

            return response()->json([
                'success' => true,
                'message' => 'Контракт успешно создан',
                'data' => $contract->load(['currency', 'project'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании контракта: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получение контракта по ID
     */
    public function show($id): JsonResponse
    {
        try {
            $contract = $this->repository->findContract($id);

            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $contract
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении контракта: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновление контракта
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $request->validate([
                'number' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0',
                'currency_id' => 'nullable|exists:currencies,id',
                'date' => 'required|date',
                'returned' => 'boolean',
                'files' => 'nullable|array'
            ]);

            $data = $request->only([
                'number',
                'amount',
                'currency_id',
                'date',
                'returned',
                'files'
            ]);

            $contract = $this->repository->updateContract($id, $data);

            return response()->json([
                'success' => true,
                'message' => 'Контракт успешно обновлен',
                'data' => $contract->load(['currency', 'project'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении контракта: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удаление контракта
     */
    public function destroy($id): JsonResponse
    {
        try {
            $result = $this->repository->deleteContract($id);

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Контракт успешно удален'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении контракта: ' . $e->getMessage()
            ], 500);
        }
    }
}
