<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\FinancialAccountResource;
use App\Repositories\FinancialAccountsRepository;
use App\Services\FinancialAccountService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialAccountsController extends BaseController
{
    public function __construct(
        private readonly FinancialAccountsRepository $repository,
        private readonly FinancialAccountService $financialAccountService,
    ) {}

    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $items = $this->repository->getAccountsWithMetrics();

        return $this->successResponse([
            'items' => FinancialAccountResource::collection($items)->resolve(),
        ]);
    }

    /**
     * @param  int  $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $account = $this->repository->getAccountDetails($id);
        if (! $account) {
            return $this->errorResponse(__('api.common.not_found'), 404);
        }

        $companyId = $this->getCurrentCompanyId();
        $page = (int) request()->input('page', 1);
        $perPage = (int) request()->input('per_page', 20);
        $history = $this->financialAccountService->getGroupedHistory(
            $id,
            $companyId,
            $perPage,
            $page,
        );

        return $this->successResponse([
            'item' => (new FinancialAccountResource($account))->resolve(),
            'history' => [
                'items' => $history->items(),
                'meta' => [
                    'current_page' => $history->currentPage(),
                    'per_page' => $history->perPage(),
                    'total' => $history->total(),
                    'last_page' => $history->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * @param  int  $id
     * @param  Request  $request
     * @return JsonResponse
     */
    public function history(int $id, Request $request): JsonResponse
    {
        $account = $this->repository->getAccountDetails($id);
        if (! $account) {
            return $this->errorResponse(__('api.common.not_found'), 404);
        }

        $companyId = $this->getCurrentCompanyId();
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);
        $dateFrom = $request->filled('date_from') ? Carbon::parse((string) $request->input('date_from')) : null;
        $dateTo = $request->filled('date_to') ? Carbon::parse((string) $request->input('date_to')) : null;

        $history = $this->financialAccountService->getGroupedHistory(
            $id,
            $companyId,
            $perPage,
            $page,
            $dateFrom,
            $dateTo,
        );

        return $this->successResponse([
            'items' => $history->items(),
            'meta' => [
                'current_page' => $history->currentPage(),
                'per_page' => $history->perPage(),
                'total' => $history->total(),
                'last_page' => $history->lastPage(),
            ],
        ]);
    }

    /**
     * @param  int  $id
     * @param  Request  $request
     * @return JsonResponse
     */
    public function balanceAt(int $id, Request $request): JsonResponse
    {
        $account = $this->repository->getAccountDetails($id);
        if (! $account) {
            return $this->errorResponse(__('api.common.not_found'), 404);
        }

        if (! $request->filled('date')) {
            return $this->errorResponse(__('api.validation.required', ['attribute' => 'date']), 422);
        }

        $companyId = $this->getCurrentCompanyId();
        $date = Carbon::parse((string) $request->input('date'));
        $balance = $this->financialAccountService->getBalanceAt($id, $date, $companyId);

        return $this->successResponse([
            'financial_account_id' => $id,
            'date' => $date->toIso8601String(),
            'balance' => $balance,
        ]);
    }
}
