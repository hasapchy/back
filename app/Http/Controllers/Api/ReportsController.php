<?php

namespace App\Http\Controllers\Api;

use App\Repositories\TransactionsRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер отчётов (по компании, с фильтром по дате и выбором валюты).
 */
class ReportsController extends BaseController
{
    public function __construct(
        protected TransactionsRepository $transactionsRepository
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
}
