<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ProductStatus;
use App\Models\TransactionCategory;
use App\Models\Unit;
use App\Models\OrderCategory;
use App\Models\OrderStatus;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Spatie\Permission\Traits\HasRoles;

class AppController extends Controller
{
    use HasRoles;
    public function getCurrencyList()
    {
        $user = $this->getAuthenticatedUser();

        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $userPermissions = $this->getUserPermissions($user);
        $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);
        $cacheKey = $hasAccessToNonDefaultCurrencies ? 'currencies_all' : 'currencies_default_only';

        $items = CacheService::getReferenceData($cacheKey, function() use ($hasAccessToNonDefaultCurrencies) {
            $query = Currency::where('status', 1);
            if (!$hasAccessToNonDefaultCurrencies) {
                $query->where('is_default', true);
            }
            return $query->get();
        });

        return response()->json($items);
    }

    public function getUnitsList()
    {
        $items = CacheService::getReferenceData('units_all', function() {
            return Unit::all();
        });

        return response()->json($items);
    }

    public function getProductStatuses()
    {

        $items = CacheService::getReferenceData('product_statuses_all', function() {
            return ProductStatus::all();
        });

        return response()->json($items);
    }


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
            'currency_code' => $currency->code,
            'currency_symbol' => $currency->symbol
        ]);
    }

}
