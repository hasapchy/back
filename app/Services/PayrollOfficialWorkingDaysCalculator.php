<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyProductionCalendarDay;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PayrollOfficialWorkingDaysCalculator
{
    /**
     * @return array<string, bool>
     */
    public function nonWorkingDateKeySet(int $companyId, Carbon $from, Carbon $to): array
    {
        return CompanyProductionCalendarDay::query()
            ->where('company_id', $companyId)
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $to->toDateString())
            ->pluck('date')
            ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])
            ->all();
    }

    /**
     * Дни месяца, не входящие в официальную норму: по графику компании и по производственному календарю.
     * Если дата нерабочая по графику (например воскресенье), она учитывается только в графике, даже при
     * дублировании в производственном календаре. В календаре — только дни, рабочие по графику, но помеченные в календаре.
     *
     * @return array{schedule_off_dates: list<string>, calendar_off_dates: list<string>}
     */
    public function monthNormNonWorkingDatesSplit(Company $company, Carbon $monthStart, Carbon $monthEnd): array
    {
        $from = $monthStart->copy()->startOfDay();
        $to = $monthEnd->copy()->startOfDay();
        $nonWorking = $this->nonWorkingDateKeySet((int) $company->id, $from, $to);
        $schedule = is_array($company->work_schedule) ? $company->work_schedule : [];

        $scheduleOff = [];
        $calendarOff = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $scheduleWorking = $this->isScheduleWorkingDay($schedule, $cursor);
            $inCalendar = isset($nonWorking[$key]);

            if ($scheduleWorking && ! $inCalendar) {
                $cursor->addDay();

                continue;
            }

            if (! $scheduleWorking) {
                $scheduleOff[] = $key;
            } elseif ($inCalendar) {
                $calendarOff[] = $key;
            }
            $cursor->addDay();
        }

        return [
            'schedule_off_dates' => array_values(array_unique($scheduleOff)),
            'calendar_off_dates' => array_values(array_unique($calendarOff)),
        ];
    }

    /**
     * @param  array<string, mixed>  $workSchedule
     */
    private function isScheduleWorkingDay(array $workSchedule, Carbon $day): bool
    {
        $isoDow = (int) $day->dayOfWeekIso;
        $cfg = $workSchedule[$isoDow] ?? null;
        if (! is_array($cfg)) {
            return false;
        }

        return ! empty($cfg['enabled']);
    }

    /**
     * @param  array<string, mixed>  $workSchedule
     * @param  array<string, bool>  $nonWorkingSet
     */
    private function countOfficialWorkingDaysBetween(array $workSchedule, array $nonWorkingSet, Carbon $from, Carbon $to): int
    {
        $fromDay = $from->copy()->startOfDay();
        $toDay = $to->copy()->startOfDay();
        if ($fromDay->gt($toDay)) {
            return 0;
        }

        $n = 0;
        $cursor = $fromDay->copy();
        while ($cursor->lte($toDay)) {
            if ($this->isOfficialWorkingDay($workSchedule, $nonWorkingSet, $cursor)) {
                $n++;
            }
            $cursor->addDay();
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $workSchedule
     * @param  array<string, bool>  $nonWorkingSet
     */
    private function isOfficialWorkingDay(array $workSchedule, array $nonWorkingSet, Carbon $day): bool
    {
        $key = $day->toDateString();
        if (isset($nonWorkingSet[$key])) {
            return false;
        }

        $isoDow = (int) $day->dayOfWeekIso;
        $cfg = $workSchedule[$isoDow] ?? null;
        if (! is_array($cfg)) {
            return false;
        }

        return ! empty($cfg['enabled']);
    }

    /**
     * Одна выборка производственного календаря и расчёт нормы/отработано на сотрудника за месяц.
     *
     * @return array{
     *   official_working_days_norm: int,
     *   official_working_days_worked: int,
     *   official_worked_breakdown?: array{
     *     month_from: string,
     *     month_to: string,
     *     employment_from: string,
     *     employment_to: string,
     *     employment_differs_from_month: bool,
     *     leave_periods: list<array{leave_type_name: string, date_from: string, date_to: string, official_days: int}>,
     *     leave_official_days_total: int
     *   }
     * }
     */
    public function prorationSharesForUser(Company $company, ?User $user, Carbon $monthStart, Carbon $monthEnd): array
    {
        $from = $monthStart->copy()->startOfDay();
        $to = $monthEnd->copy()->startOfDay();
        $nonWorking = $this->nonWorkingDateKeySet((int) $company->id, $from, $to);
        $schedule = is_array($company->work_schedule) ? $company->work_schedule : [];
        $norm = $this->countOfficialWorkingDaysBetween($schedule, $nonWorking, $from, $to);

        $empFrom = $from->copy();
        $empTo = $to->copy();

        if ($user?->hire_date) {
            $h = $user->hire_date->copy()->startOfDay();
            if ($h->gt($empFrom)) {
                $empFrom = $h;
            }
        }

        if ($user?->dismissal_date) {
            $d = $user->dismissal_date->copy()->startOfDay();
            if ($d->lt($empTo)) {
                $empTo = $d;
            }
        }

        $worked = 0;
        if (! $empFrom->gt($empTo)) {
            $worked = $this->countOfficialWorkingDaysBetween($schedule, $nonWorking, $empFrom, $empTo);
        }

        $leaveSubtract = 0;
        $leavePeriods = [];
        if ($user !== null && ! $empFrom->gt($empTo)) {
            $leaveBlock = $this->officialWorkingLeaveDeductions(
                (int) $company->id,
                (int) $user->id,
                $schedule,
                $nonWorking,
                $empFrom,
                $empTo
            );
            $leaveSubtract = $leaveBlock['count'];
            $leavePeriods = $leaveBlock['periods'];
        }

        if ($user !== null && $worked > 0 && $leaveSubtract > 0) {
            $worked = max(0, $worked - $leaveSubtract);
        }

        $out = [
            'official_working_days_norm' => $norm,
            'official_working_days_worked' => $worked,
        ];

        if ($user !== null) {
            $out['official_worked_breakdown'] = [
                'month_from' => $from->toDateString(),
                'month_to' => $to->toDateString(),
                'employment_from' => $empFrom->toDateString(),
                'employment_to' => $empTo->toDateString(),
                'employment_differs_from_month' => $empFrom->toDateString() !== $from->toDateString()
                    || $empTo->toDateString() !== $to->toDateString(),
                'leave_periods' => $leavePeriods,
                'leave_official_days_total' => $leaveSubtract,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $workSchedule
     * @param  array<string, bool>  $nonWorkingSet
     * @return array{count: int, periods: list<array{leave_type_name: string, date_from: string, date_to: string, official_days: int}>}
     */
    private function officialWorkingLeaveDeductions(
        int $companyId,
        int $userId,
        array $workSchedule,
        array $nonWorkingSet,
        Carbon $empFrom,
        Carbon $empTo
    ): array {
        $penaltyLeaveTypeIds = LeaveType::query()->where('is_penalty', true)->pluck('id');
        if ($penaltyLeaveTypeIds->isEmpty()) {
            return ['count' => 0, 'periods' => []];
        }

        $leaves = Leave::query()
            ->from('leaves')
            ->where('leaves.company_id', $companyId)
            ->where('leaves.user_id', $userId)
            ->whereIn('leaves.leave_type_id', $penaltyLeaveTypeIds)
            ->whereDate('leaves.date_from', '<=', $empTo->toDateString())
            ->whereDate('leaves.date_to', '>=', $empFrom->toDateString())
            ->join('leave_types', 'leave_types.id', '=', 'leaves.leave_type_id')
            ->select([
                'leaves.date_from',
                'leaves.date_to',
                'leave_types.name as leave_type_name',
            ])
            ->get();

        $byDate = [];
        $periods = [];

        foreach ($leaves as $leave) {
            $leaveFrom = Carbon::parse($leave->date_from)->startOfDay();
            $leaveTo = Carbon::parse($leave->date_to)->startOfDay();
            $rangeStart = $empFrom->copy()->gt($leaveFrom) ? $empFrom->copy() : $leaveFrom->copy();
            $rangeEnd = $empTo->copy()->lt($leaveTo) ? $empTo->copy() : $leaveTo->copy();
            if ($rangeStart->gt($rangeEnd)) {
                continue;
            }

            $officialDaysThisLeave = 0;
            $cursor = $rangeStart->copy();
            while ($cursor->lte($rangeEnd)) {
                if ($this->isOfficialWorkingDay($workSchedule, $nonWorkingSet, $cursor)) {
                    $key = $cursor->toDateString();
                    if (! isset($byDate[$key])) {
                        $byDate[$key] = true;
                    }
                    $officialDaysThisLeave++;
                }
                $cursor->addDay();
            }

            if ($officialDaysThisLeave > 0) {
                $periods[] = [
                    'leave_type_name' => (string) ($leave->leave_type_name ?? ''),
                    'date_from' => $rangeStart->toDateString(),
                    'date_to' => $rangeEnd->toDateString(),
                    'official_days' => $officialDaysThisLeave,
                ];
            }
        }

        return [
            'count' => count($byDate),
            'periods' => $periods,
        ];
    }

    /**
     * @param  array{official_working_days_norm: int, official_working_days_worked: int}  $shares
     * @return array{
     *   official_working_days_norm: int,
     *   official_working_days_worked: int,
     *   monthly_salary_base: float,
     *   prorated_salary_amount: float
     * }
     */
    public function prorationRowForAmount(array $shares, float $monthlyAmount): array
    {
        $norm = $shares['official_working_days_norm'];
        $worked = $shares['official_working_days_worked'];

        $prorated = 0.0;
        if ($norm > 0) {
            $prorated = round($monthlyAmount * $worked / $norm, 2);
        }

        return [
            'official_working_days_norm' => $norm,
            'official_working_days_worked' => $worked,
            'monthly_salary_base' => round($monthlyAmount, 2),
            'prorated_salary_amount' => $prorated,
        ];
    }

    /**
     * @param  string  $monthYm  Формат Y-m
     */
    public function warnIfOfficialNormZeroWithPositiveSalary(
        int $companyId,
        string $monthYm,
        int $officialNorm,
        bool $hasPositiveSalaryAmount,
        ?string $context = null
    ): void {
        if ($officialNorm !== 0 || ! $hasPositiveSalaryAmount) {
            return;
        }

        $payload = [
            'company_id' => $companyId,
            'month' => $monthYm,
        ];
        if ($context !== null) {
            $payload['context'] = $context;
        }
        Log::warning('Payroll proration: official working days norm is zero', $payload);
    }

    /**
     * @return array{
     *   official_working_days_norm: int,
     *   official_working_days_worked: int,
     *   monthly_salary_base: float,
     *   prorated_salary_amount: float
     * }
     */
    public function getProrationForUser(
        Company $company,
        ?User $user,
        Carbon $monthStart,
        Carbon $monthEnd,
        float $monthlyAmount
    ): array {
        $shares = $this->prorationSharesForUser($company, $user, $monthStart, $monthEnd);
        $monthYm = $monthStart->copy()->startOfDay()->format('Y-m');
        $row = $this->prorationRowForAmount($shares, $monthlyAmount);
        $this->warnIfOfficialNormZeroWithPositiveSalary(
            (int) $company->id,
            $monthYm,
            $shares['official_working_days_norm'],
            $monthlyAmount > 0,
            null
        );

        return $row;
    }
}
