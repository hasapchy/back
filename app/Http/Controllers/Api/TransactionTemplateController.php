<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreTransactionTemplateRequest;
use App\Http\Requests\UpdateTransactionTemplateRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\TransactionTemplateResource;
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
        return $this->successResponse([
            'items' => TransactionTemplateResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
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
        return $this->successResponse(TransactionTemplateResource::collection($items)->resolve());
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
            return $this->errorResponse('Шаблон не найден', 404);
        }
        return $this->successResponse(new TransactionTemplateResource($template));
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
            return $this->errorResponse('Шаблон не найден', 404);
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
            $client = Client::with(['phones', 'emails', 'balances.currency', 'balances.users'])->find($template->client_id);
            if ($client && $this->canPerformAction('clients', 'view', $client)) {
                $item['client_id'] = $template->client_id;
                $item['client'] = (new ClientResource($client))->toArray(request());
            }
        }

        if ($template->project_id) {
            $project = Project::find($template->project_id);
            if ($project && $this->canPerformAction('projects', 'view', $project)) {
                $item['project_id'] = $template->project_id;
                $item['project'] = $project;
            }
        }

        return $this->successResponse($item);
    }

    /**
     * @param StoreTransactionTemplateRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreTransactionTemplateRequest $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();

        if (!$this->hasPermission('transaction_templates_create')) {
            return $this->errorResponse('У вас нет прав на создание шаблонов транзакций', 403);
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
        return $this->successResponse(new TransactionTemplateResource($created), 'Шаблон создан');
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
            return $this->errorResponse('Шаблон не найден', 404);
        }
        if (!$this->canPerformAction('transaction_templates', 'update', $template)) {
            return $this->errorResponse('У вас нет прав на редактирование этого шаблона', 403);
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
        return $this->successResponse(new TransactionTemplateResource($updated), 'Шаблон обновлен');
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
            return $this->errorResponse('Шаблон не найден', 404);
        }
        if (!$this->canPerformAction('transaction_templates', 'delete', $template)) {
            return $this->errorResponse('У вас нет прав на удаление этого шаблона', 403);
        }

        $this->repository->deleteItem($template->id);
        return $this->successResponse(null, 'Шаблон удален');
    }
}
