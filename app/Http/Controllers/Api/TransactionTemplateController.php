<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreTransactionTemplateRequest;
use App\Http\Requests\UpdateTransactionTemplateRequest;
use App\Models\Client;
use App\Models\Project;
use App\Repositories\TransactionTemplateRepository;
use Illuminate\Http\Request;

class TransactionTemplateController extends BaseController
{
    protected TransactionTemplateRepository $repository;

    public function __construct(TransactionTemplateRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $filters = [];
        if ($request->has('cash_id')) {
            $filters['cash_id'] = $request->input('cash_id');
        }
        if ($request->has('type') && in_array($request->input('type'), ['0', '1'], true)) {
            $filters['type'] = (int) $request->input('type');
        }
        if ($request->has('search')) {
            $filters['search'] = $request->input('search');
        }

        $items = $this->repository->getItemsWithPagination($perPage, $page, $filters);
        return $this->paginatedResponse($items);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $filters = [];
        if ($request->has('cash_id')) {
            $filters['cash_id'] = $request->input('cash_id');
        }
        if ($request->has('type') && in_array($request->input('type'), ['0', '1'], true)) {
            $filters['type'] = (int) $request->input('type');
        }

        $items = $this->repository->getAllItems($filters);
        return response()->json($items);
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $template = $this->repository->getItemById((int) $id);
        if (!$template) {
            return $this->notFoundResponse('Шаблон не найден');
        }
        return response()->json(['item' => $template]);
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function apply($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $template = $this->repository->getItemById((int) $id);
        if (!$template) {
            return $this->notFoundResponse('Шаблон не найден');
        }

        $item = [
            'name' => $template->name,
            'icon' => $template->icon,
            'type' => $template->type,
            'amount' => $template->amount,
            'currency_id' => $template->currency_id,
            'category_id' => $template->category_id,
            'date' => $template->date,
            'note' => $template->note,
        ];

        if ($template->cash_id && $this->checkCashRegisterAccess((int) $template->cash_id) === null) {
            $item['cash_id'] = $template->cash_id;
            if ($template->relationLoaded('cashRegister') && $template->cashRegister) {
                $item['cash_register'] = $template->cashRegister;
            }
        }

        if ($template->client_id) {
            $client = Client::find($template->client_id);
            if ($client && $this->canPerformAction('clients', 'view', $client)) {
                $item['client_id'] = $template->client_id;
                $item['client'] = $client;
            }
        }

        if ($template->project_id) {
            $project = Project::find($template->project_id);
            if ($project && $this->canPerformAction('projects', 'view', $project)) {
                $item['project_id'] = $template->project_id;
                $item['project'] = $project;
            }
        }

        return response()->json(['item' => $item]);
    }

    /**
     * @param StoreTransactionTemplateRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreTransactionTemplateRequest $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();

        if (!$this->hasPermission('transaction_templates_create')) {
            return $this->forbiddenResponse('У вас нет прав на создание шаблонов транзакций');
        }

        $validated = $request->validated();
        $cashAccessCheck = $this->checkCashRegisterAccess((int) ($validated['cash_id'] ?? 0));
        if ($cashAccessCheck) {
            return $cashAccessCheck;
        }

        $data = [
            'name' => $validated['name'],
            'icon' => $validated['icon'],
            'cash_id' => $validated['cash_id'],
            'amount' => $validated['amount'] ?? null,
            'type' => (int) $validated['type'],
            'currency_id' => $validated['currency_id'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'date' => isset($validated['date']) ? $validated['date'] : now(),
            'note' => $validated['note'] ?? null,
            'creator_id' => $userId,
        ];

        $created = $this->repository->createItem($data);
        return response()->json(['item' => $created, 'message' => 'Шаблон создан']);
    }

    /**
     * @param UpdateTransactionTemplateRequest $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateTransactionTemplateRequest $request, $id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $template = $this->repository->getItemById((int) $id);
        if (!$template) {
            return $this->notFoundResponse('Шаблон не найден');
        }
        if (!$this->canPerformAction('transaction_templates', 'update', $template)) {
            return $this->forbiddenResponse('У вас нет прав на редактирование этого шаблона');
        }

        $validated = $request->validated();
        if (isset($validated['cash_id'])) {
            $cashAccessCheck = $this->checkCashRegisterAccess((int) $validated['cash_id']);
            if ($cashAccessCheck) {
                return $cashAccessCheck;
            }
        }

        $data = array_filter([
            'name' => $validated['name'] ?? null,
            'icon' => $validated['icon'] ?? null,
            'cash_id' => $validated['cash_id'] ?? null,
            'amount' => $validated['amount'] ?? null,
            'type' => isset($validated['type']) ? (int) $validated['type'] : null,
            'currency_id' => $validated['currency_id'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'date' => $validated['date'] ?? null,
            'note' => $validated['note'] ?? null,
        ], fn ($v) => $v !== null);

        $updated = $this->repository->updateItem((int) $id, $data);
        return response()->json(['item' => $updated, 'message' => 'Шаблон обновлен']);
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $template = $this->repository->getItemById((int) $id);
        if (!$template) {
            return $this->notFoundResponse('Шаблон не найден');
        }
        if (!$this->canPerformAction('transaction_templates', 'delete', $template)) {
            return $this->forbiddenResponse('У вас нет прав на удаление этого шаблона');
        }

        $this->repository->deleteItem($template->id);
        return response()->json(['message' => 'Шаблон удален']);
    }
}
