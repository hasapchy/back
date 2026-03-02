<?php

namespace App\Services;

use App\Models\RecSchedule;
use App\Models\User;
use App\Repositories\RecSchedulesRepository;
use App\Repositories\TransactionsRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecurringTransactionRunService
{
    public function __construct(
        private RecSchedulesRepository $schedulesRepository,
        private TransactionsRepository $transactionsRepository
    ) {
    }

    /**
     * Создать транзакции по всем due-расписаниям (next_run_at <= $upToDate).
     *
     * @param Carbon|null $upToDate
     * @return array{created: int, errors: array<string>}
     */
    public function runDue(?\Carbon\Carbon $upToDate = null): array
    {
        $upToDate = $upToDate ?? Carbon::today('Asia/Ashgabat');
        $schedules = $this->schedulesRepository->getDueSchedules($upToDate);
        $created = 0;
        $errors = [];

        foreach ($schedules as $schedule) {
            try {
                $this->runOne($schedule);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = sprintf('rec_schedule #%d: %s', $schedule->id, $e->getMessage());
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    /**
     * Создать одну транзакцию по расписанию на дату next_run_at и обновить расписание.
     *
     * @param RecSchedule $schedule
     * @return void
     * @throws \Throwable
     */
    public function runOne(RecSchedule $schedule): void
    {
        $runDate = $schedule->next_run_at->copy();
        if ($runDate->gt(Carbon::today('Asia/Ashgabat')->endOfDay())) {
            return;
        }

        $creator = User::find($schedule->creator_id);
        if (!$creator) {
            throw new \RuntimeException('Creator not found');
        }

        $previousRequest = app()->bound('request') ? request() : null;
        $previousUser = Auth::user();

        $companyId = $schedule->company_id !== null ? (string) $schedule->company_id : '';
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_Company_ID' => $companyId]);
        app()->instance('request', $request);
        Auth::login($creator);

        try {
            $schedule->loadMissing('template');
            $template = $schedule->template;
            if (!$template) {
                $schedule->is_active = false;
                $schedule->save();
                return;
            }

            $data = $template->toTransactionData($runDate);
            $data['creator_id'] = $schedule->creator_id;
            $this->transactionsRepository->createItem($data);
            $this->schedulesRepository->incrementOccurrenceAndSetNext($schedule);
        } finally {
            if ($previousRequest !== null) {
                app()->instance('request', $previousRequest);
            }
            if ($previousUser !== null) {
                Auth::login($previousUser);
            }
        }
    }
}
