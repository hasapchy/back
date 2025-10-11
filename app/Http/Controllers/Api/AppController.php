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
    // Получение списка валют
    public function getCurrencyList()
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Кэшируем справочник валют на 2 часа
        $items = CacheService::getReferenceData('currencies_all', function() {
            return Currency::where('status', 1)->get();
        });

        return response()->json($items);
    }

    // получение единиц измерения
    public function getUnitsList()
    {
        // Кэшируем справочник единиц на 2 часа
        $items = CacheService::getReferenceData('units_all', function() {
            return Unit::all();
        });

        return response()->json($items);
    }

    // получение статусов товаров
    public function getProductStatuses()
    {
        // Кэшируем справочник статусов товаров на 2 часа
        $items = CacheService::getReferenceData('product_statuses_all', function() {
            return ProductStatus::all();
        });

        return response()->json($items);
    }


    // получение актуального курса валюты
    public function getCurrencyExchangeRate($currencyId)
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $currency = Currency::find($currencyId);
        if (!$currency) {
            return response()->json(['error' => 'Валюта не найдена'], 404);
        }

        // Доступ к курсу валюты открыт без специальных прав

        $rateHistory = $currency->exchangeRateHistories()
            ->where('start_date', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->orderBy('start_date', 'desc')
            ->first();

        $exchangeRate = $rateHistory ? $rateHistory->exchange_rate : 1;

        return response()->json([
            'currency_id' => $currencyId,
            'exchange_rate' => $exchangeRate,
            'currency_name' => $currency->name,
            'currency_code' => $currency->code,
            'currency_symbol' => $currency->symbol
        ]);
    }


    // public function getOrderCategories()
    // {
    //     $items = OrderCategory::select('id', 'name')->orderBy('name')->get();
    //     return response()->json($items);
    // }

    // public function getOrderStatuses()
    // {

    //     $items = OrderStatus::with(['category' => function ($q) {
    //         $q->select('id', 'name', 'user_id', 'color');
    //     }])
    //         ->get(['id', 'name', 'category_id']);

    //     return response()->json($items);
    // }
}
