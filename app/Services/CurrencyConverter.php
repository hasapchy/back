<?php

namespace App\Services;

use App\Models\Currency;

class CurrencyConverter
{
    /**
     * Конвертирует сумму из валюты источника в валюту назначения.
     *
     * Логика конвертации:
     * 1. Если валюты одинаковые - возвращаем сумму без изменений
     * 2. Если одна из валют - манат (базовая), конвертируем напрямую
     * 3. Если обе валюты не манат - конвертируем через манат
     *
     * @param float    $amount
     * @param Currency $fromCurrency Валюта источника
     * @param Currency $toCurrency   Валюта назначения
     * @param Currency|null $defaultCurrency Базовая валюта (манат), если не передана, будет выбрана из БД
     * @return float
     */
    public static function convert($amount, Currency $fromCurrency, Currency $toCurrency, ?Currency $defaultCurrency = null)
    {
        if (!$defaultCurrency) {
            $defaultCurrency = Currency::where('is_default', true)->first();
        }

        // Если валюты одинаковые - возвращаем сумму без изменений
        if($fromCurrency->id === $toCurrency->id) {
            return $amount;
        }

        // Если исходная валюта - манат (базовая)
        if($fromCurrency->id === $defaultCurrency->id) {
            return $amount / $toCurrency->exchange_rate;
        }

        // Если целевая валюта - манат (базовая)
        if($toCurrency->id === $defaultCurrency->id) {
            return $amount * $fromCurrency->exchange_rate;
        }

        // Если обе валюты не манат - конвертируем через манат
        // Сначала в манат, потом в целевую валюту
        $amountInManat = $amount / $fromCurrency->exchange_rate;
        return $amountInManat * $toCurrency->exchange_rate;
    }
}
