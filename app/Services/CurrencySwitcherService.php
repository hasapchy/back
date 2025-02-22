<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\CurrencyHistory;

class CurrencySwitcherService
{
    public function getConversionRate(string $currencyCode, $date): float
    {
        $selectedCurrency = $this->getSelectedCurrency($currencyCode);
        if (!$selectedCurrency) {
            return 1;
        }
        $currencyHistory = CurrencyHistory::where('currency_id', $selectedCurrency->id)
            ->where('start_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->where('end_date', '>=', $date)->orWhereNull('end_date');
            })
            ->first();

        return $currencyHistory ? $currencyHistory->exchange_rate : 1;
    }

    public function getSelectedCurrency(string $currencyCode): ?Currency
    {
        return Currency::where('code', $currencyCode)->first();
    }
}