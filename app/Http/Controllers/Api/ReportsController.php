<?php

namespace App\Http\Controllers\Api;

use App\Repositories\TransactionsRepository;
use App\Repositories\ReportsRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер отчётов (по компании, с фильтром по дате и выбором валюты).
 */
/**
 * @group Финансы
 * @subgroup Отчеты
 */
class ReportsController extends BaseController
{
    public function __construct(
        protected TransactionsRepository $transactionsRepository,
        protected ReportsRepository $reportsRepository
    ) {
    }

    /**
     * Отчёт: доходы и расходы по категориям транзакций.
     *
     * @param Request $request date_filter_type, start_date, end_date, currency_mode (report|default), category_ids
     * @return JsonResponse
     */
    public function byCategories(Request $request): JsonResponse
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();
        $dateFilterType = $request->query('date_filter_type');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $currencyMode = $request->query('currency_mode', 'report');
        if (!in_array($currencyMode, ['report', 'default'], true)) {
            $currencyMode = 'report';
        }

        $categoryIds = $request->query('category_ids');
        if ($categoryIds) {
            if (is_string($categoryIds)) {
                $categoryIds = explode(',', $categoryIds);
            }
            $categoryIds = array_filter(array_map('intval', (array) $categoryIds));
            $categoryIds = ! empty($categoryIds) ? $categoryIds : null;
        } else {
            $categoryIds = null;
        }

        $data = $this->transactionsRepository->getAmountsByCategory(
            $userUuid,
            $dateFilterType,
            $startDate,
            $endDate,
            $currencyMode,
            $categoryIds
        );

        return $this->successResponse($data);
    }

    /**
     * Отчёт ДДС (факт, прямой метод) с исключением внутренних трансферов.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function cashflow(Request $request): JsonResponse
    {
        $dateFilterType = $request->query('date_filter_type');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $currencyMode = $request->query('currency_mode', 'report');
        $groupBy = $request->query('group_by', 'month');
        $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;
        $clientId = $request->query('client_id') ? (int) $request->query('client_id') : null;
        $categoryId = $request->query('category_id') ? (int) $request->query('category_id') : null;
        $cashIds = $request->query('cash_ids');

        if (! in_array($currencyMode, ['report', 'default'], true)) {
            $currencyMode = 'report';
        }
        if (! in_array($groupBy, ['day', 'week', 'month'], true)) {
            $groupBy = 'month';
        }
        if (is_string($cashIds)) {
            $cashIds = explode(',', $cashIds);
        }
        $cashIds = is_array($cashIds) ? array_values(array_filter(array_map('intval', $cashIds))) : null;

        $data = $this->reportsRepository->getCashflowReport(
            $dateFilterType,
            $startDate,
            $endDate,
            $currencyMode,
            $cashIds,
            $projectId,
            $clientId,
            $categoryId,
            $groupBy
        );

        return $this->successResponse($data);
    }

    /**
     * Отчёт по контрагентам: приход/расход/нетто + долговые показатели.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function counterparties(Request $request): JsonResponse
    {
        $dateFilterType = $request->query('date_filter_type');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $currencyMode = $request->query('currency_mode', 'report');
        $mode = $request->query('mode', 'net');
        $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;

        if (! in_array($currencyMode, ['report', 'default'], true)) {
            $currencyMode = 'report';
        }
        if (! in_array($mode, ['income', 'expense', 'net'], true)) {
            $mode = 'net';
        }

        $data = $this->reportsRepository->getCounterpartiesReport(
            $dateFilterType,
            $startDate,
            $endDate,
            $currencyMode,
            $mode,
            $projectId
        );

        return $this->successResponse($data);
    }

    /**
     * Отчёт по заказам: факт денежных потоков и остаток долга.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function orders(Request $request): JsonResponse
    {
        $dateFilterType = $request->query('date_filter_type');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $currencyMode = $request->query('currency_mode', 'report');
        $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;

        if (! in_array($currencyMode, ['report', 'default'], true)) {
            $currencyMode = 'report';
        }

        $data = $this->reportsRepository->getOrdersReport(
            $dateFilterType,
            $startDate,
            $endDate,
            $currencyMode,
            $projectId
        );

        return $this->successResponse($data);
    }

    /**
     * Отчёт по договорам: факт денежных потоков и остаток долга.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function contracts(Request $request): JsonResponse
    {
        $dateFilterType = $request->query('date_filter_type');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $currencyMode = $request->query('currency_mode', 'report');
        $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;

        if (! in_array($currencyMode, ['report', 'default'], true)) {
            $currencyMode = 'report';
        }

        $data = $this->reportsRepository->getContractsReport(
            $dateFilterType,
            $startDate,
            $endDate,
            $currencyMode,
            $projectId
        );

        return $this->successResponse($data);
    }

    /**
     * Техническая схема для этапа план/факт ДДС.
     *
     * @return JsonResponse
     */
    public function planFactBlueprint(): JsonResponse
    {
        return $this->successResponse($this->reportsRepository->getPlanFactBlueprint());
    }
}
