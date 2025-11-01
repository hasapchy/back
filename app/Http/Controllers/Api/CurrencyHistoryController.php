<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class CurrencyHistoryController extends Controller
{
    // Получение истории курсов для конкретной валюты
    public function index(Request $request, $currencyId)
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $currency = Currency::find($currencyId);
            if (!$currency) {
                return response()->json(['error' => 'Валюта не найдена'], 404);
            }

            // Проверяем права доступа к валюте
            // Если пользователь имеет доступ к истории курсов, он должен иметь доступ ко всем валютам
            $userPermissions = $user->permissions->pluck('name')->toArray();
            $hasAccessToCurrencyHistory = in_array('currency_history_view', $userPermissions);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);

            // Если нет доступа к истории курсов И нет доступа к не-дефолтным валютам И это не базовая валюта - запрещаем доступ
            if (!$hasAccessToCurrencyHistory && !$hasAccessToNonDefaultCurrencies && !$currency->is_default) {
                return response()->json(['error' => 'Нет доступа к этой валюте'], 403);
            }

            $history = CurrencyHistory::where('currency_id', $currencyId)
                ->orderBy('start_date', 'desc')
                ->get();

            return response()->json([
                'currency' => $currency,
                'history' => $history
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Ошибка при получении истории курсов: ' . $e->getMessage()], 500);
        }
    }

    // Создание новой записи в истории курсов
    public function store(Request $request, $currencyId)
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $currency = Currency::find($currencyId);
            if (!$currency) {
                return response()->json(['error' => 'Валюта не найдена'], 404);
            }

            // Проверяем права доступа к валюте
            // Если пользователь имеет доступ к истории курсов, он должен иметь доступ ко всем валютам
            $userPermissions = $user->permissions->pluck('name')->toArray();
            $hasAccessToCurrencyHistory = in_array('currency_history_view', $userPermissions);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);

            // Если нет доступа к истории курсов И нет доступа к не-дефолтным валютам И это не базовая валюта - запрещаем доступ
            if (!$hasAccessToCurrencyHistory && !$hasAccessToNonDefaultCurrencies && !$currency->is_default) {
                return response()->json(['error' => 'Нет доступа к этой валюте'], 403);
            }

            $request->validate([
                'exchange_rate' => 'required|numeric|min:0.000001',
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after:start_date'
            ]);

            DB::beginTransaction();

            // Всегда закрываем все предыдущие активные записи для этой валюты
            // Устанавливаем дату окончания равной дате начала новой записи
            CurrencyHistory::where('currency_id', $currencyId)
                ->whereNull('end_date')
                ->update(['end_date' => $request->start_date]);

            $history = CurrencyHistory::create([
                'currency_id' => $currencyId,
                'exchange_rate' => $request->exchange_rate,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Курс валюты успешно добавлен',
                'history' => $history
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'Ошибка при создании записи курса: ' . $e->getMessage()], 500);
        }
    }

    // Обновление записи в истории курсов
    public function update(Request $request, $currencyId, $historyId)
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $currency = Currency::find($currencyId);
            if (!$currency) {
                return response()->json(['error' => 'Валюта не найдена'], 404);
            }

            // Проверяем права доступа к валюте
            // Если пользователь имеет доступ к истории курсов, он должен иметь доступ ко всем валютам
            $userPermissions = $user->permissions->pluck('name')->toArray();
            $hasAccessToCurrencyHistory = in_array('currency_history_view', $userPermissions);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);

            // Если нет доступа к истории курсов И нет доступа к не-дефолтным валютам И это не базовая валюта - запрещаем доступ
            if (!$hasAccessToCurrencyHistory && !$hasAccessToNonDefaultCurrencies && !$currency->is_default) {
                return response()->json(['error' => 'Нет доступа к этой валюте'], 403);
            }

            $history = CurrencyHistory::where('currency_id', $currencyId)
                ->where('id', $historyId)
                ->first();

            if (!$history) {
                return response()->json(['error' => 'Запись в истории не найдена'], 404);
            }

            $request->validate([
                'exchange_rate' => 'required|numeric|min:0.000001',
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after:start_date'
            ]);

            DB::beginTransaction();

            // Если обновляем активную запись (без end_date), закрываем все другие активные записи
            if (!$request->end_date) {
                CurrencyHistory::where('currency_id', $currencyId)
                    ->where('id', '!=', $historyId)
                    ->whereNull('end_date')
                    ->update(['end_date' => $request->start_date]);
            }

            $history->update([
                'exchange_rate' => $request->exchange_rate,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Курс валюты успешно обновлен',
                'history' => $history
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'Ошибка при обновлении записи курса: ' . $e->getMessage()], 500);
        }
    }

    // Удаление записи из истории курсов
    public function destroy(Request $request, $currencyId, $historyId)
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $currency = Currency::find($currencyId);
            if (!$currency) {
                return response()->json(['error' => 'Валюта не найдена'], 404);
            }

            // Проверяем права доступа к валюте
            // Если пользователь имеет доступ к истории курсов, он должен иметь доступ ко всем валютам
            $userPermissions = $user->permissions->pluck('name')->toArray();
            $hasAccessToCurrencyHistory = in_array('currency_history_view', $userPermissions);
            $hasAccessToNonDefaultCurrencies = in_array('settings_currencies_view', $userPermissions);

            // Если нет доступа к истории курсов И нет доступа к не-дефолтным валютам И это не базовая валюта - запрещаем доступ
            if (!$hasAccessToCurrencyHistory && !$hasAccessToNonDefaultCurrencies && !$currency->is_default) {
                return response()->json(['error' => 'Нет доступа к этой валюте'], 403);
            }

            $history = CurrencyHistory::where('currency_id', $currencyId)
                ->where('id', $historyId)
                ->first();

            if (!$history) {
                return response()->json(['error' => 'Запись в истории не найдена'], 404);
            }

            DB::beginTransaction();

            $history->delete();

            DB::commit();

            return response()->json([
                'message' => 'Запись курса успешно удалена'
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'Ошибка при удалении записи курса: ' . $e->getMessage()], 500);
        }
    }

    // Получение всех валют с их текущими курсами
    public function getCurrenciesWithRates()
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Для истории курсов показываем все активные валюты, у которых есть история
            // Если пользователь имеет доступ к странице истории курсов, он должен видеть все валюты
            $query = Currency::where('status', 1);

            $currencies = $query->with(['exchangeRateHistories' => function ($query) {
                $query->where('start_date', '<=', now()->toDateString())
                    ->where(function ($q) {
                        $q->whereNull('end_date')
                            ->orWhere('end_date', '>=', now()->toDateString());
                    })
                    ->orderBy('start_date', 'desc')
                    ->limit(1);
            }])
                ->get();

            $result = $currencies->map(function ($currency) {
                $currentRate = $currency->exchangeRateHistories->first();
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

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Ошибка при получении валют с курсами: ' . $e->getMessage()], 500);
        }
    }
}
