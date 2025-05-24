<?php

namespace App\Services;

use App\Models\Currency;

class CurrencyConverter
{
    /**
     * Конвертирует сумму из валюты источника в валюту назначения.
     *
     * Формула: 
     * $convertedAmount = $amount / $fromCurrency->exchange_rate * $defaultCurrency->exchange_rate * $toCurrency->exchange_rate;
     *
     * @param float    $amount
     * @param Currency $fromCurrency Валюта продажи
     * @param Currency $toCurrency   Валюта кассы
     * @param Currency|null $defaultCurrency Валюта по умолчанию, если не передана, будет выбрана из БД
     * @return float
     */
    public static function convert($amount, Currency $fromCurrency, Currency $toCurrency, ?Currency $defaultCurrency = null)
    {
        if (!$defaultCurrency) {
            $defaultCurrency = Currency::where('is_default', true)->first();
        }
        if($fromCurrency->id === $toCurrency->id) {
            return $amount;
        }
        // return $amount / $fromCurrency->exchange_rate * $defaultCurrency->exchange_rate * $toCurrency->exchange_rate;
        return $amount / $fromCurrency->exchange_rate * $toCurrency->exchange_rate;
    }
}