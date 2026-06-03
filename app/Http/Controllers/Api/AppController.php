<?php

namespace App\Http\Controllers\Api;

use App\Models\Currency;
use App\Models\Unit;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Traits\HasRoles;

/**
 * @group Система
 * @subgroup Приложение
 */
class AppController extends BaseController
{
    use HasRoles;

    /**
     * Получить список валют
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrencyList()
    {
        $user = $this->getAuthenticatedUser();

        if (!$user) {
            return $this->errorResponse(null, 401);
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

        return $this->successResponse($items);
    }

    /**
     * Получить список единиц измерения
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUnitsList()
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $companyId = $this->getCurrentCompanyId();
        $cacheKey = 'units_list_'.($companyId ?? 'none');

        $items = CacheService::getReferenceData($cacheKey, function () use ($companyId) {
            return Unit::forCompanyCatalog($companyId)->get();
        });

        return $this->successResponse($items);
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

        return $this->successResponse($items);
    }

    /**
     * Получить курс обмена валюты
     *
     * @param int $currencyId ID валюты
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrencyExchangeRate(\Illuminate\Http\Request $request, int $currencyId)
    {
        $user = $this->getAuthenticatedUser();

        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $currency = Currency::find($currencyId);
        if (! $currency) {
            return $this->errorResponse(__('Валюта не найдена'), 404);
        }

        $companyId = $this->getCurrentCompanyId();
        $date = $request->query('date');
        $dateKey = $date ? md5((string) $date) : 'current';
        $cacheKey = "currency_exchange_rate_{$currencyId}_{$companyId}_{$dateKey}";

        $exchangeRate = CacheService::getReferenceData($cacheKey, function () use ($currency, $companyId, $date) {
            if ($date) {
                return (float) $currency->getExchangeRateForCompany($companyId, (string) $date);
            }
            $rateHistory = $currency->getCurrentExchangeRateForCompany($companyId);

            return $rateHistory ? (float) $rateHistory->exchange_rate : 1.0;
        });

        return $this->successResponse([
            'currency_id' => $currencyId,
            'exchange_rate' => $exchangeRate,
            'currency_name' => $currency->name,
            'currency_symbol' => $currency->code,
            'rate_date' => $date,
        ]);
    }

}
