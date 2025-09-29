<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ProductStatus;
use App\Models\TransactionCategory;
use App\Models\Unit;
use App\Models\OrderCategory;
use App\Models\OrderStatus;
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

        // Проверяем, есть ли у пользователя доступ ко всем валютам
        $hasAccessToAllCurrencies = $user->can('currencies_access_all');

        if ($hasAccessToAllCurrencies) {
            // Если есть доступ ко всем валютам - показываем все активные валюты
            $items = Currency::where('status', 1)->get();
        } else {
            // Если нет доступа ко всем валютам - показываем только базовую валюту (TMT)
            $items = Currency::where('status', 1)
                ->where('is_default', true)
                ->get();
        }

        return response()->json($items);
    }

    // получение единиц измерения
    public function getUnitsList()
    {
        $items = Unit::all();
        return response()->json($items);
    }

    // получение статусов товаров
    public function getProductStatuses()
    {
        $items = ProductStatus::all();

        return response()->json($items);
    }

    // получение категорий транзакций
    public function getTransactionCategories()
    {
        $userUuid = optional(auth('api')->user())->id;

        $items = TransactionCategory::select('id', 'name', 'type')
            ->where(function ($query) use ($userUuid) {
                $query->whereNull('user_id') // системные категории
                    ->orWhere('user_id', $userUuid); // пользовательские категории
            })
            ->get();
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

        // Проверяем права доступа к валюте
        $hasAccessToAllCurrencies = $user->can('currencies_access_all');

        // Если нет доступа ко всем валютам и это не базовая валюта - запрещаем доступ
        if (!$hasAccessToAllCurrencies && !$currency->is_default) {
            return response()->json(['error' => 'Нет доступа к этой валюте'], 403);
        }

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
