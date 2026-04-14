<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreCurrencyHistoryRequest;
use App\Http\Requests\UpdateCurrencyHistoryRequest;
use App\Http\Resources\CurrencyHistoryResource;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use App\Models\User;
use App\Services\CacheService;
use App\Support\CompanyScopedPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Контроллер для работы с историей курсов валют
 */
class CurrencyHistoryController extends BaseController
{
    /**
     * Получить историю курсов валюты с пагинацией
     *
     * @param  int  $currencyId  ID валюты
     * @return JsonResponse
     */
    public function index(Request $request, $currencyId)
    {
        try {
            $user = $this->requireAuthenticatedUser();
            $currency = Currency::findOrFail($currencyId);

            $accessCheck = $this->checkCurrencyAccess($user, $currency);
            if ($accessCheck) {
                return $accessCheck;
            }

            $page = max((int) $request->get('page', 1), 1);
            $perPage = max((int) $request->get('per_page', 20), 1);

            $companyId = $this->getCurrentCompanyId();

            $cacheKey = "currency_history_{$currencyId}_{$companyId}";

            $history = CacheService::getReferenceData($cacheKey, function () use ($currencyId, $companyId) {
                return CurrencyHistory::where('currency_id', $currencyId)
                    ->forCompanyOrGlobal($companyId)
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

            return $this->successResponse([
                'currency' => $currency,
                'history' => CurrencyHistoryResource::collection($items)->resolve(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении истории курсов: '.$e->getMessage(), 500);
        }
    }

    /**
     * Получить историю курсов всех доступных валют с пагинацией
     *
     * @return JsonResponse
     */
    public function indexAll(Request $request)
    {
        try {
            $user = $this->requireAuthenticatedUser();
            $companyId = $this->getCurrentCompanyId();

            $userPermissions = $this->getUserPermissions($user);
            $hasAccessToCurrencyHistory = CompanyScopedPermissions::userCanViewCurrencyHistory($user);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions, true);

            $page = max((int) $request->get('page', 1), 1);
            $perPage = max((int) $request->get('per_page', 20), 1);

            $query = CurrencyHistory::with('currency')
                ->forCompanyOrGlobal($companyId)
                ->orderBy('start_date', 'desc');

            if (! $hasAccessToCurrencyHistory && ! $hasAccessToNonDefaultCurrencies) {
                $query->whereHas('currency', function ($currencyQuery) {
                    $currencyQuery->where('is_default', true);
                });
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return $this->successResponse([
                'history' => CurrencyHistoryResource::collection($paginator->items())->resolve(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении истории курсов: '.$e->getMessage(), 500);
        }
    }

    /**
     * Создать новую запись в истории курсов валюты
     *
     * @param  int  $currencyId  ID валюты
     * @return JsonResponse
     */
    public function store(StoreCurrencyHistoryRequest $request, $currencyId)
    {
        try {
            $user = $this->requireAuthenticatedUser();
            $currency = Currency::findOrFail($currencyId);

            $accessCheck = $this->checkCurrencyAccess($user, $currency);
            if ($accessCheck) {
                return $accessCheck;
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
                'end_date' => $validatedData['end_date'] ?? null,
            ]);

            DB::commit();

            CacheService::invalidateCurrenciesCache();

            return $this->successResponse(new CurrencyHistoryResource($history), 'Курс валюты успешно добавлен');
        } catch (\Exception $e) {
            DB::rollback();

            return $this->errorResponse('Ошибка при создании записи курса: '.$e->getMessage(), 500);
        }
    }

    /**
     * Обновить запись в истории курсов валюты
     *
     * @param  int  $currencyId  ID валюты
     * @param  int  $historyId  ID записи истории
     * @return JsonResponse
     */
    public function update(UpdateCurrencyHistoryRequest $request, $currencyId, $historyId)
    {
        try {
            $user = $this->requireAuthenticatedUser();
            $currency = Currency::findOrFail($currencyId);

            $accessCheck = $this->checkCurrencyAccess($user, $currency);
            if ($accessCheck) {
                return $accessCheck;
            }

            $companyId = $this->getCurrentCompanyId();

            $history = CurrencyHistory::where('currency_id', $currencyId)
                ->where('id', $historyId)
                ->forCompany($companyId)
                ->first();

            if (! $history) {
                return $this->errorResponse('Запись в истории не найдена', 404);
            }

            $validatedData = $request->validated();

            DB::beginTransaction();

            if (! isset($validatedData['end_date']) || ! $validatedData['end_date']) {
                CurrencyHistory::where('currency_id', $currencyId)
                    ->where('id', '!=', $historyId)
                    ->whereNull('end_date')
                    ->forCompany($companyId)
                    ->update(['end_date' => $validatedData['start_date']]);
            }

            $history->update([
                'exchange_rate' => $validatedData['exchange_rate'],
                'start_date' => $validatedData['start_date'],
                'end_date' => $validatedData['end_date'] ?? null,
            ]);

            DB::commit();

            CacheService::invalidateCurrenciesCache();

            return $this->successResponse(new CurrencyHistoryResource($history), 'Курс валюты успешно обновлен');
        } catch (\Exception $e) {
            DB::rollback();

            return $this->errorResponse('Ошибка при обновлении записи курса: '.$e->getMessage(), 500);
        }
    }

    /**
     * Удалить запись из истории курсов валюты
     *
     * @param  int  $currencyId  ID валюты
     * @param  int  $historyId  ID записи истории
     * @return JsonResponse
     */
    public function destroy(Request $request, $currencyId, $historyId)
    {
        try {
            $user = $this->requireAuthenticatedUser();
            $currency = Currency::findOrFail($currencyId);

            $accessCheck = $this->checkCurrencyAccess($user, $currency);
            if ($accessCheck) {
                return $accessCheck;
            }

            $companyId = $this->getCurrentCompanyId();

            $history = CurrencyHistory::where('currency_id', $currencyId)
                ->where('id', $historyId)
                ->forCompany($companyId)
                ->first();

            if (! $history) {
                return $this->errorResponse('Запись в истории не найдена', 404);
            }

            DB::beginTransaction();

            $history->delete();

            DB::commit();

            CacheService::invalidateCurrenciesCache();

            return $this->successResponse(null, 'Запись курса успешно удалена');
        } catch (\Exception $e) {
            DB::rollback();

            return $this->errorResponse('Ошибка при удалении записи курса: '.$e->getMessage(), 500);
        }
    }

    /**
     * Получить валюты с текущими курсами
     *
     * @return JsonResponse
     */
    public function getCurrenciesWithRates()
    {
        try {
            $user = $this->requireAuthenticatedUser();
            $companyId = $this->getCurrentCompanyId();

            $userPermissions = $this->getUserPermissions($user);
            $hasAccessToCurrencyHistory = CompanyScopedPermissions::userCanViewCurrencyHistory($user);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions, true);

            $cacheKey = "currencies_with_rates_{$companyId}_{$hasAccessToCurrencyHistory}_{$hasAccessToNonDefaultCurrencies}";

            $result = CacheService::getReferenceData($cacheKey, function () use ($hasAccessToCurrencyHistory, $hasAccessToNonDefaultCurrencies, $companyId) {
                $query = Currency::where('status', 1);

                if ($companyId) {
                    $query->where(function ($q) use ($companyId) {
                        $q->where('company_id', $companyId)->orWhereNull('company_id');
                    });
                } else {
                    $query->whereNull('company_id');
                }

                if (! $hasAccessToCurrencyHistory && ! $hasAccessToNonDefaultCurrencies) {
                    $query->where('is_default', true);
                }

                $currencies = $query->get();

                return $currencies->map(function ($currency) use ($companyId) {
                    $currentRate = $currency->getCurrentExchangeRateForCompany($companyId);
                    $previousRate = CurrencyHistory::query()
                        ->where('currency_id', $currency->id)
                        ->forCompanyOrGlobal($companyId)
                        ->orderBy('start_date', 'desc')
                        ->skip(1)
                        ->value('exchange_rate');

                    return [
                        'id' => $currency->id,
                        'name' => $currency->name,
                        'symbol' => $currency->symbol,
                        'is_default' => $currency->is_default,
                        'is_report' => $currency->is_report,
                        'current_rate' => $currentRate ? $currentRate->exchange_rate : 1,
                        'previous_rate' => $previousRate,
                        'rate_start_date' => $currentRate ? $currentRate->start_date : null,
                    ];
                });
            });

            return $this->successResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении валют с курсами: '.$e->getMessage(), 500);
        }
    }

    /**
     * Проверить доступ пользователя к валюте
     *
     * @param  User  $user
     * @param  Currency  $currency
     * @return JsonResponse|null
     */
    protected function checkCurrencyAccess($user, $currency)
    {
        $userPermissions = $this->getUserPermissions($user);
        $hasAccessToCurrencyHistory = CompanyScopedPermissions::userCanViewCurrencyHistory($user);
        $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions, true);

        if (! $hasAccessToCurrencyHistory && ! $hasAccessToNonDefaultCurrencies && ! $currency->is_default) {
            return $this->errorResponse('Нет доступа к этой валюте', 403);
        }

        return null;
    }
}
