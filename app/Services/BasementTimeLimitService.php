<?php

namespace App\Services;

use Carbon\Carbon;

class BasementTimeLimitService
{
    /**
     * Проверяет, можно ли редактировать заказ
     *
     * @param string $createdAt
     * @param string $action
     * @return array
     */
    public static function checkTimeLimit(string $createdAt, string $action = 'edit'): array
    {
        $orderCreatedAt = Carbon::parse($createdAt);
        $timeLimitHours = $action === 'delete'
            ? config('basement.order_delete_limit_hours', 8)
            : config('basement.order_edit_limit_hours', 8);

        $timeLimitFromCreation = $orderCreatedAt->copy()->addHours($timeLimitHours);
        $now = Carbon::now();

        if ($now->lt($timeLimitFromCreation)) {
            $hoursRemaining = $now->diffInHours($timeLimitFromCreation, false);

            return [
                'allowed' => false,
                'hours_remaining' => $hoursRemaining,
                'time_limit' => $timeLimitHours,
                'created_at' => $createdAt,
                'unlock_at' => $timeLimitFromCreation->toISOString()
            ];
        }

        return [
            'allowed' => true,
            'hours_remaining' => 0,
            'time_limit' => $timeLimitHours,
            'created_at' => $createdAt
        ];
    }

    /**
     * Проверяет, можно ли редактировать заказ
     *
     * @param string $createdAt
     * @return array
     */
    public static function checkEditLimit(string $createdAt): array
    {
        return self::checkTimeLimit($createdAt, 'edit');
    }

    /**
     * Проверяет, можно ли удалить заказ
     *
     * @param string $createdAt
     * @return array
     */
    public static function checkDeleteLimit(string $createdAt): array
    {
        return self::checkTimeLimit($createdAt, 'delete');
    }

    /**
     * Получает конфигурацию временных ограничений
     *
     * @return array
     */
    public static function getTimeConfig(): array
    {
        return [
            'edit_limit_hours' => config('basement.order_edit_limit_hours', 8),
            'delete_limit_hours' => config('basement.order_delete_limit_hours', 8),
        ];
    }
}
