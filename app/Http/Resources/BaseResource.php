<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

abstract class BaseResource extends JsonResource
{
    /**
     * Форматировать дату в формате Y-m-d
     *
     * @param mixed $date
     * @return string|null
     */
    protected function formatDate($date)
    {
        if (!$date) {
            return null;
        }

        if (is_string($date)) {
            try {
                return date('Y-m-d', strtotime($date));
            } catch (\Exception $e) {
                return $date;
            }
        }

        if ($date instanceof \Carbon\Carbon || $date instanceof \DateTime) {
            return $date->format('Y-m-d');
        }

        return $date;
    }

    /**
     * Форматировать дату и время в формате Y-m-d H:i:s
     *
     * @param mixed $datetime
     * @return string|null
     */
    protected function formatDateTime($datetime)
    {
        if (!$datetime) {
            return null;
        }

        if (is_string($datetime)) {
            try {
                return date('Y-m-d H:i:s', strtotime($datetime));
            } catch (\Exception $e) {
                return $datetime;
            }
        }

        if ($datetime instanceof \Carbon\Carbon || $datetime instanceof \DateTime) {
            return $datetime->format('Y-m-d H:i:s');
        }

        return $datetime;
    }

    /**
     * Получить полный URL для asset
     *
     * @param string|null $path
     * @return string|null
     */
    protected function assetUrl($path)
    {
        if (!$path) {
            return null;
        }

        return asset("storage/{$path}");
    }

    /**
     * Форматировать число с указанным количеством знаков после запятой
     *
     * @param float|int|null $number
     * @param int $decimals
     * @return float|null
     */
    protected function formatNumber($number, $decimals = 2)
    {
        if ($number === null || $number instanceof MissingValue) {
            return null;
        }

        return round((float) $number, $decimals);
    }

    /**
     * Форматировать валюту
     *
     * @param float|int|null $amount
     * @param int $decimals
     * @return float|null
     */
    protected function formatCurrency($amount, $decimals = 2)
    {
        return $this->formatNumber($amount, $decimals);
    }

    /**
     * Преобразовать значение в boolean
     *
     * @param mixed $value
     * @return bool
     */
    protected function toBoolean($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on']);
        }

        return (bool) $value;
    }

    /**
     * Безопасно получить значение из ресурса
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getResourceValue(string $key, $default = null)
    {
        return data_get($this->resource, $key, $default);
    }
}

