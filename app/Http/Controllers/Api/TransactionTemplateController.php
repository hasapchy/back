<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreTransactionTemplateRequest;
use App\Http\Requests\UpdateTransactionTemplateRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\TransactionTemplateResource;
use App\Models\Client;
use App\Models\Template;
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
        $filters = $this->transactionTemplateListFilters($request);
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

        $items = $this->repository->getAllItems($this->transactionTemplateListFilters($request));
        return $this->successResponse(TransactionTemplateResource::collection($items)->resolve());
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(int $id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $template = $this->repository->getItemById($id);
        if (!$template) {
            return $this->errorResponse('Шаблон не найден', 404);
        }
        return $this->successResponse(new TransactionTemplateResource($template));
    }

    /**
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function apply(Request $request, int $id)
    {
        $user = $this->requireAuthenticatedUser();

        $template = $this->repository->getItemById($id);
        if (!$template) {
            return $this->errorResponse('Шаблон не найден', 404);
        }

        $data = (new TransactionTemplateResource($template))->toArray($request);

        if (!$template->cash_id || $this->checkCashRegisterAccess((int) $template->cash_id) !== null) {
            unset($data['cash_id'], $data['cash_register']);
        }

        unset($data['client_id'], $data['client']);
        if ($template->client_id) {
            $client = Client::with(['phones', 'emails', 'balances.currency', 'balances.users'])->find($template->client_id);
            if ($client && $user->can('view', $client)) {
                $data['client_id'] = $template->client_id;
                $data['client'] = (new ClientResource($client))->toArray($request);
            }
        }

        unset($data['project_id'], $data['project']);
        $project = $template->project;
        if ($project && $user->can('view', $project)) {
            $data['project_id'] = $template->project_id;
            $data['project'] = $project->only(['id', 'name']);
        }

        return $this->successResponse($data);
    }

    /**
     * @param StoreTransactionTemplateRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreTransactionTemplateRequest $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();

        $this->authorize('create', Template::class);

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
    public function update(UpdateTransactionTemplateRequest $request, int $id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $template = $this->repository->getItemById($id);
        if (!$template) {
            return $this->errorResponse('Шаблон не найден', 404);
        }
        $this->authorize('update', $template);

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
            'note' => $validated['note'] ?? null,
        ], fn ($v) => $v !== null);

        $updated = $this->repository->updateItem($id, $data);
        return $this->successResponse(new TransactionTemplateResource($updated), 'Шаблон обновлен');
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(int $id)
    {
        $this->getAuthenticatedUserIdOrFail();

        $template = $this->repository->getItemById($id);
        if (!$template) {
            return $this->errorResponse('Шаблон не найден', 404);
        }
        $this->authorize('delete', $template);

        $this->repository->deleteItem($template->id);
        return $this->successResponse(null, 'Шаблон удален');
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionTemplateListFilters(Request $request): array
    {
        $filters = [];
        if ($request->has('cash_id')) {
            $filters['cash_id'] = $request->input('cash_id');
        }
        if ($request->has('type') && in_array($request->input('type'), ['0', '1'], true)) {
            $filters['type'] = (int) $request->input('type');
        }

        return $filters;
    }
}
