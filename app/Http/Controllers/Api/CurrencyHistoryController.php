<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\StoreCurrencyHistoryRequest;
use App\Http\Requests\UpdateCurrencyHistoryRequest;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Контроллер для работы с историей курсов валют
 */
class CurrencyHistoryController extends BaseController
{
    /**
     * Получить историю курсов валюты с пагинацией
     *
     * @param Request $request
     * @param int $currencyId ID валюты
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $currencyId)
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$user) {
                return $this->unauthorizedResponse();
            }

            $currency = Currency::findOrFail($currencyId);

            $userPermissions = $this->getUserPermissions($user);
            $hasAccessToCurrencyHistory = in_array('currency_history_view', $userPermissions);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);

            $page = max((int)$request->get('page', 1), 1);
            $perPage = max((int)$request->get('per_page', 20), 1);

            if (!$hasAccessToCurrencyHistory && !$hasAccessToNonDefaultCurrencies && !$currency->is_default) {
                return $this->forbiddenResponse('Нет доступа к этой валюте');
            }

            $companyId = $this->getCurrentCompanyId();

            $cacheKey = "currency_history_{$currencyId}_{$companyId}";

            $history = CacheService::getReferenceData($cacheKey, function () use ($currencyId, $companyId) {
                return CurrencyHistory::where('currency_id', $currencyId)
                    ->forCompany($companyId)
                    ->orderBy('start_date', 'desc')
                    ->get();
            });
            $historyCollection = collect($history)->values();
            $total = $historyCollection->count();
            $items = $historyCollection->forPage($page, $perPage)->values();

            $paginator = new LengthAwarePaginator(
                $items,
                $total,
                $perPage,
                $page,
                ['path' => $request->url(), 'pageName' => 'page']
            );

            return response()->json([
                'currency' => $currency,
                'history' => $items,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage()
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении истории курсов: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Создать новую запись в истории курсов валюты
     *
     * @param StoreCurrencyHistoryRequest $request
     * @param int $currencyId ID валюты
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreCurrencyHistoryRequest $request, $currencyId)
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$user) {
                return $this->unauthorizedResponse();
            }

            $currency = Currency::findOrFail($currencyId);

            $userPermissions = $this->getUserPermissions($user);
            $hasAccessToCurrencyHistory = in_array('currency_history_view', $userPermissions);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);

            if (!$hasAccessToCurrencyHistory && !$hasAccessToNonDefaultCurrencies && !$currency->is_default) {
                return $this->forbiddenResponse('Нет доступа к этой валюте');
            }

            $validatedData = $request->validated();

            $companyId = $this->getCurrentCompanyId();

            DB::beginTransaction();

            CurrencyHistory::where('currency_id', $currencyId)
                ->whereNull('end_date')
                ->forCompany($companyId)
                ->update(['end_date' => $validatedData['start_date']]);

            $history = CurrencyHistory::create([
                'currency_id' => $currencyId,
                'company_id' => $companyId,
                'exchange_rate' => $validatedData['exchange_rate'],
                'start_date' => $validatedData['start_date'],
                'end_date' => $validatedData['end_date'] ?? null
            ]);

            DB::commit();

            CacheService::invalidateCurrenciesCache();

            return response()->json(['history' => $history, 'message' => 'Курс валюты успешно добавлен']);
        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Ошибка при создании записи курса: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Обновить запись в истории курсов валюты
     *
     * @param UpdateCurrencyHistoryRequest $request
     * @param int $currencyId ID валюты
     * @param int $historyId ID записи истории
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateCurrencyHistoryRequest $request, $currencyId, $historyId)
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$user) {
                return $this->unauthorizedResponse();
            }

            $currency = Currency::findOrFail($currencyId);

            $userPermissions = $this->getUserPermissions($user);
            $hasAccessToCurrencyHistory = in_array('currency_history_view', $userPermissions);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);

            if (!$hasAccessToCurrencyHistory && !$hasAccessToNonDefaultCurrencies && !$currency->is_default) {
                return $this->forbiddenResponse('Нет доступа к этой валюте');
            }

            $companyId = $this->getCurrentCompanyId();

            $history = CurrencyHistory::where('currency_id', $currencyId)
                ->where('id', $historyId)
                ->forCompany($companyId)
                ->first();

            if (!$history) {
                return response()->json(['error' => 'Запись в истории не найдена'], 404);
            }

            $validatedData = $request->validated();

            DB::beginTransaction();

            if (!isset($validatedData['end_date']) || !$validatedData['end_date']) {
                CurrencyHistory::where('currency_id', $currencyId)
                    ->where('id', '!=', $historyId)
                    ->whereNull('end_date')
                    ->forCompany($companyId)
                    ->update(['end_date' => $validatedData['start_date']]);
            }

            $history->update([
                'exchange_rate' => $validatedData['exchange_rate'],
                'start_date' => $validatedData['start_date'],
                'end_date' => $validatedData['end_date'] ?? null
            ]);

            DB::commit();

            CacheService::invalidateCurrenciesCache();

            return response()->json(['history' => $history, 'message' => 'Курс валюты успешно обновлен']);
        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Ошибка при обновлении записи курса: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Удалить запись из истории курсов валюты
     *
     * @param Request $request
     * @param int $currencyId ID валюты
     * @param int $historyId ID записи истории
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, $currencyId, $historyId)
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$user) {
                return $this->unauthorizedResponse();
            }

            $currency = Currency::findOrFail($currencyId);

            $userPermissions = $this->getUserPermissions($user);
            $hasAccessToCurrencyHistory = in_array('currency_history_view', $userPermissions);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);

            if (!$hasAccessToCurrencyHistory && !$hasAccessToNonDefaultCurrencies && !$currency->is_default) {
                return $this->forbiddenResponse('Нет доступа к этой валюте');
            }

            $companyId = $this->getCurrentCompanyId();

            $history = CurrencyHistory::where('currency_id', $currencyId)
                ->where('id', $historyId)
                ->forCompany($companyId)
                ->first();

            if (!$history) {
                return $this->notFoundResponse('Запись в истории не найдена');
            }

            DB::beginTransaction();

            $history->delete();

            DB::commit();

            CacheService::invalidateCurrenciesCache();

            return response()->json(['message' => 'Запись курса успешно удалена']);
        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Ошибка при удалении записи курса: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получить валюты с текущими курсами
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrenciesWithRates()
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$user) {
                return $this->unauthorizedResponse();
            }

            $companyId = $this->getCurrentCompanyId();

            $userPermissions = $this->getUserPermissions($user);
            $hasAccessToCurrencyHistory = in_array('currency_history_view', $userPermissions);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);

            $cacheKey = "currencies_with_rates_{$companyId}_{$hasAccessToCurrencyHistory}_{$hasAccessToNonDefaultCurrencies}";

            $result = CacheService::getReferenceData($cacheKey, function () use ($hasAccessToCurrencyHistory, $hasAccessToNonDefaultCurrencies, $companyId) {
                $query = Currency::where('status', 1);

                if (!$hasAccessToCurrencyHistory && !$hasAccessToNonDefaultCurrencies) {
                    $query->where('is_default', true);
                }

                $currencies = $query->get();

                return $currencies->map(function ($currency) use ($companyId) {
                    $currentRate = $currency->getCurrentExchangeRateForCompany($companyId);
                    return [
                        'id' => $currency->id,
                        'code' => $currency->code,
                        'name' => $currency->name,
                        'symbol' => $currency->symbol,
                        'is_default' => $currency->is_default,
                        'is_report' => $currency->is_report,
                        'current_rate' => $currentRate ? $currentRate->exchange_rate : 1,
                        'rate_start_date' => $currentRate ? $currentRate->start_date : null
                    ];
                });
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении валют с курсами: ' . $e->getMessage(), 500);
        }
    }
}
