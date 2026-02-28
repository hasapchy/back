<?php

namespace App\Repositories;

use App\Models\RecSchedule;
use App\Services\RecurrenceRuleService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class RecSchedulesRepository extends BaseRepository
{
    /**
     * @return array<string>
     */
    private function getBaseRelations(): array
    {
        return [
            'creator:id,name,surname',
            'template',
        ];
    }

    /**
     * @param int $perPage
     * @param int $page
     * @param int|null $userId
     * @param bool $viewAll
     * @param string|null $companyId
     * @param int|null $templateId
     * @return LengthAwarePaginator
     */
    public function getItemsWithPagination(int $perPage = 20, int $page = 1, ?int $userId = null, bool $viewAll = false, ?string $companyId = null, ?int $templateId = null): LengthAwarePaginator
    {
        $companyId = $companyId ?? $this->getCurrentCompanyId();

        $query = RecSchedule::query()
            ->with($this->getBaseRelations())
            ->orderBy('rec_schedules.next_run_at', 'asc');

        if (!$viewAll && $userId !== null) {
            $query->where('rec_schedules.creator_id', $userId);
        }

        if ($companyId !== null) {
            $query->where('rec_schedules.company_id', $companyId);
        }

        if ($templateId !== null) {
            $query->where('rec_schedules.template_id', $templateId);
        }

        return $query->paginate($perPage, ['rec_schedules.*'], 'page', $page);
    }

    /**
     * @param int $id
     * @return RecSchedule|null
     */
    public function getItemById(int $id): ?RecSchedule
    {
        return RecSchedule::query()
            ->with($this->getBaseRelations())
            ->where('rec_schedules.id', $id)
            ->first();
    }

    /**
     * Расписания, по которым нужно создать транзакцию (next_run_at <= $upToDate).
     *
     * @param Carbon|null $upToDate
     * @return Collection<int, RecSchedule>
     */
    public function getDueSchedules(?\Carbon\Carbon $upToDate = null): Collection
    {
        $upToDate = $upToDate ?? Carbon::today('Asia/Ashgabat');

        return RecSchedule::query()
            ->with('template')
            ->where('rec_schedules.is_active', true)
            ->whereDate('rec_schedules.next_run_at', '<=', $upToDate)
            ->orderBy('rec_schedules.next_run_at')
            ->get();
    }

    /**
     * @param array<string, mixed> $data
     * @return RecSchedule
     */
    /**
     * @param array<string, mixed> $data
     * @return RecSchedule
     */
    public function createItem(array $data): RecSchedule
    {
        $rule = $data['recurrence_rule'] ?? [];
        if (is_string($rule)) {
            $rule = json_decode($rule, true) ?? [];
        }

        $schedule = new RecSchedule();
        $schedule->creator_id = $data['creator_id'];
        $schedule->company_id = $data['company_id'] ?? $this->getCurrentCompanyId();
        $schedule->template_id = $data['template_id'];
        $schedule->start_date = $data['start_date'];
        $schedule->recurrence_rule = $rule;
        $schedule->end_date = $data['end_date'] ?? null;
        $schedule->end_count = isset($data['end_count']) ? (int) $data['end_count'] : null;
        $schedule->occurrence_count = 0;
        $schedule->is_active = true;

        $service = app(RecurrenceRuleService::class);
        $schedule->next_run_at = $service->getFirstRunAt($schedule);
        $schedule->save();

        return $schedule->load($this->getBaseRelations());
    }

    /**
     * @param int $id
     * @param array<string, mixed> $data
     * @return RecSchedule
     */
    public function updateItem(int $id, array $data): RecSchedule
    {
        $schedule = RecSchedule::findOrFail($id);

        $fillable = [
            'template_id', 'start_date', 'recurrence_rule', 'end_date', 'end_count', 'is_active',
        ];

        foreach ($fillable as $key) {
            if (array_key_exists($key, $data)) {
                $schedule->$key = $data[$key];
            }
        }

        if (isset($schedule->recurrence_rule) && is_string($schedule->recurrence_rule)) {
            $schedule->recurrence_rule = json_decode($schedule->recurrence_rule, true) ?? [];
        }

        $schedule->save();

        if (array_key_exists('recurrence_rule', $data) || array_key_exists('start_date', $data)) {
            $service = app(RecurrenceRuleService::class);
            $schedule->next_run_at = $service->getFirstRunAt($schedule);
            $schedule->save();
        }

        return $schedule;
    }

    /**
     * @param RecSchedule $schedule
     * @return void
     */
    public function incrementOccurrenceAndSetNext(RecSchedule $schedule): void
    {
        $schedule->occurrence_count = $schedule->occurrence_count + 1;

        $service = app(RecurrenceRuleService::class);
        $next = $service->getNextRunAt($schedule, $schedule->next_run_at);

        if ($next === null || ($schedule->end_date !== null && $next->gt($schedule->end_date))) {
            $schedule->is_active = false;
            $schedule->next_run_at = $schedule->next_run_at;
        } else {
            $schedule->next_run_at = $next;
        }

        $schedule->save();
    }

    /**
     * @param RecSchedule $schedule
     * @return bool
     */
    public function deleteItem(RecSchedule $schedule): bool
    {
        return $schedule->delete();
    }
}
