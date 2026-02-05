<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Models\Currency;
use App\Models\Unit;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Traits\HasRoles;

class AppController extends BaseController
{
    use HasRoles;

    /**
     * Получить список валют (требуется X-Company-ID и tenant — маршрут под middleware tenant.required).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrencyList()
    {
        $user = $this->getAuthenticatedUser();

        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $userPermissions = $this->getUserPermissions($user);
        $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $hasAccessToNonDefaultCurrencies ? 'currencies_all' : 'currencies_default_only';
        $cacheKey .= '_' . ($companyId ?? 'default');

        $items = CacheService::getReferenceData($cacheKey, function() use ($hasAccessToNonDefaultCurrencies, $companyId) {
            $query = Currency::where('status', 1);

            if ($companyId) {
                // Ищем валюты для компании ИЛИ глобальные (NULL)
                $query->where(function($q) use ($companyId) {
                    $q->where('company_id', $companyId)
                      ->orWhereNull('company_id');
                });
            } else {
                $query->whereNull('company_id');
            }

            if (!$hasAccessToNonDefaultCurrencies) {
                $query->where('is_default', true);
            }

            return $query->get();
        });

        return response()->json($items);
    }

    /**
     * Получить список единиц измерения (требуется X-Company-ID и tenant — маршрут под middleware tenant.required).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUnitsList()
    {
        $items = CacheService::getReferenceData('units_all', function() {
            return Unit::all();
        });

        return response()->json($items);
    }

    /**
     * Получить список версий приложения
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVersions(): JsonResponse
    {
        $items = CacheService::getReferenceData('app_versions', function () {
            return (array) config('app_versions.versions', []);
        });

        return response()->json($items);
    }

    /**
     * Получить курс обмена валюты (требуется X-Company-ID и tenant — маршрут под middleware tenant.required).
     *
     * @param int $currencyId ID валюты
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrencyExchangeRate($currencyId)
    {
        $user = $this->getAuthenticatedUser();

        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $currency = Currency::find($currencyId);
        if (!$currency) {
            return $this->notFoundResponse('Валюта не найдена');
        }

        $companyId = $this->getCurrentCompanyId();

        $cacheKey = "currency_exchange_rate_{$currencyId}_{$companyId}";

        $exchangeRate = CacheService::getReferenceData($cacheKey, function () use ($currency, $companyId) {
            $rateHistory = $currency->getCurrentExchangeRateForCompany($companyId);
            return $rateHistory ? $rateHistory->exchange_rate : 1;
        });

        return response()->json([
            'currency_id' => $currencyId,
            'exchange_rate' => $exchangeRate,
            'currency_name' => $currency->name,
            'currency_symbol' => $currency->symbol
        ]);
    }

}
