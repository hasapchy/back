<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CurrencyHistoryController extends Controller
{
    // Получение истории курсов для конкретной валюты
    public function index(Request $request, $currencyId)
    {
        try {
            $currency = Currency::find($currencyId);
            if (!$currency) {
                return response()->json(['error' => 'Валюта не найдена'], 404);
            }

            $history = CurrencyHistory::where('currency_id', $currencyId)
                ->orderBy('start_date', 'desc')
                ->get();

            return response()->json([
                'currency' => $currency,
                'history' => $history
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching currency history: ' . $e->getMessage());
            return response()->json(['error' => 'Ошибка при получении истории курсов'], 500);
        }
    }

    // Создание новой записи в истории курсов
    public function store(Request $request, $currencyId)
    {
        try {
            $currency = Currency::find($currencyId);
            if (!$currency) {
                return response()->json(['error' => 'Валюта не найдена'], 404);
            }

            $request->validate([
                'exchange_rate' => 'required|numeric|min:0.000001',
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after:start_date'
            ]);

            DB::beginTransaction();

            // Если указана дата окончания, закрываем предыдущую запись
            if ($request->end_date) {
                CurrencyHistory::where('currency_id', $currencyId)
                    ->whereNull('end_date')
                    ->update(['end_date' => $request->start_date]);
            }

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
            DB::rollBack();
            Log::error('Error creating currency history: ' . $e->getMessage());
            return response()->json(['error' => 'Ошибка при создании записи курса'], 500);
        }
    }

    // Обновление записи в истории курсов
    public function update(Request $request, $currencyId, $historyId)
    {
        try {
            $currency = Currency::find($currencyId);
            if (!$currency) {
                return response()->json(['error' => 'Валюта не найдена'], 404);
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
            DB::rollBack();
            Log::error('Error updating currency history: ' . $e->getMessage());
            return response()->json(['error' => 'Ошибка при обновлении записи курса'], 500);
        }
    }

    // Удаление записи из истории курсов
    public function destroy(Request $request, $currencyId, $historyId)
    {
        try {
            $currency = Currency::find($currencyId);
            if (!$currency) {
                return response()->json(['error' => 'Валюта не найдена'], 404);
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
            DB::rollBack();
            Log::error('Error deleting currency history: ' . $e->getMessage());
            return response()->json(['error' => 'Ошибка при удалении записи курса'], 500);
        }
    }

    // Получение всех валют с их текущими курсами
    public function getCurrenciesWithRates()
    {
        try {
            $currencies = Currency::where('status', 1)
                ->with(['exchangeRateHistories' => function ($query) {
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
            Log::error('Error fetching currencies with rates: ' . $e->getMessage());
            return response()->json(['error' => 'Ошибка при получении валют с курсами'], 500);
        }
    }
}
