<?php

namespace App\Services;

use App\Models\RecSchedule;
use Carbon\Carbon;

class RecurrenceRuleService
{
    /**
     * Вычислить следующую дату запуска после указанной (не включая её).
     *
     * @param RecSchedule $schedule
     * @param Carbon $after Дата, после которой ищем следующую (обычно дата только что созданной транзакции)
     * @return Carbon|null Следующая дата или null, если повторение закончилось (end_date / end_count)
     */
    public function getNextRunAt(RecSchedule $schedule, Carbon $after): ?Carbon
    {
        $rule = $schedule->recurrence_rule ?? [];
        $frequency = $rule['frequency'] ?? 'daily';
        $interval = max(1, (int) ($rule['interval'] ?? 1));
        $weekdays = $rule['weekdays'] ?? [];
        $monthDay = isset($rule['month_day']) ? (int) $rule['month_day'] : null;

        if ($schedule->end_count !== null && $schedule->occurrence_count >= $schedule->end_count) {
            return null;
        }

        $candidate = match ($frequency) {
            'daily' => $this->nextDaily($after, $interval),
            'weekly' => $this->nextWeekly($after, $interval, $weekdays),
            'weekdays' => $this->nextWeekdays($after, $weekdays),
            'monthly' => $this->nextMonthly($after, $interval, $monthDay),
            default => $after->copy()->addDay(),
        };

        if ($schedule->end_date !== null && $candidate->gt($schedule->end_date)) {
            return null;
        }

        if ($schedule->end_count !== null && $schedule->occurrence_count + 1 >= $schedule->end_count) {
            return $candidate;
        }

        return $candidate;
    }

    /**
     * @param Carbon $after
     * @param int $interval
     * @return Carbon
     */
    private function nextDaily(Carbon $after, int $interval): Carbon
    {
        return $after->copy()->addDays($interval)->startOfDay();
    }

    /**
     * @param Carbon $after
     * @param int $interval
     * @param array $weekdays 0-6 (Sunday=0)
     * @return Carbon
     */
    private function nextWeekly(Carbon $after, int $interval, array $weekdays): Carbon
    {
        $next = $after->copy()->addDay()->startOfDay();

        if (!empty($weekdays)) {
            while (!in_array($next->dayOfWeek, $weekdays, true)) {
                $next->addDay();
            }
        }

        $next->addWeeks($interval - 1);
        return $next;
    }

    /**
     * @param Carbon $after
     * @param array $weekdays 0-6
     * @return Carbon
     */
    private function nextWeekdays(Carbon $after, array $weekdays): Carbon
    {
        if (empty($weekdays)) {
            return $after->copy()->addDay()->startOfDay();
        }

        $next = $after->copy()->addDay()->startOfDay();
        $maxIterations = 8;

        while ($maxIterations-- > 0) {
            if (in_array($next->dayOfWeek, $weekdays, true)) {
                return $next;
            }
            $next->addDay();
        }

        return $after->copy()->addDay()->startOfDay();
    }

    /**
     * @param Carbon $after
     * @param int $interval
     * @param int|null $monthDay 1-31
     * @return Carbon
     */
    private function nextMonthly(Carbon $after, int $interval, ?int $monthDay): Carbon
    {
        $next = $after->copy()->addMonthsNoOverflow($interval)->startOfDay();

        if ($monthDay !== null && $monthDay >= 1 && $monthDay <= 31) {
            $lastDay = $next->copy()->endOfMonth()->day;
            $day = min($monthDay, $lastDay);
            $next->day($day);
        }

        return $next;
    }

    /**
     * Вычислить первую дату запуска (next_run_at при создании расписания).
     *
     * @param RecSchedule $schedule
     * @return Carbon
     */
    public function getFirstRunAt(RecSchedule $schedule): Carbon
    {
        $start = $schedule->start_date instanceof Carbon
            ? $schedule->start_date->copy()
            : Carbon::parse($schedule->start_date)->startOfDay();

        $rule = $schedule->recurrence_rule ?? [];
        $frequency = $rule['frequency'] ?? 'daily';
        $weekdays = $rule['weekdays'] ?? [];
        $monthDay = isset($rule['month_day']) ? (int) $rule['month_day'] : null;

        if (in_array($frequency, ['weekly', 'weekdays'], true) && !empty($weekdays)) {
            return $this->alignStartToWeekdays($start, $weekdays);
        }

        if ($frequency === 'monthly' && $monthDay !== null && $monthDay >= 1 && $monthDay <= 31) {
            $lastDay = $start->copy()->endOfMonth()->day;
            $start->day(min($monthDay, $lastDay));
        }

        return $start;
    }

    /**
     * @param Carbon $start
     * @param array<int> $weekdays 0-6 (Sunday=0)
     * @return Carbon
     */
    private function alignStartToWeekdays(Carbon $start, array $weekdays): Carbon
    {
        $start = $start->copy();
        while (!in_array($start->dayOfWeek, $weekdays, true)) {
            $start->addDay();
        }
        return $start;
    }
}
