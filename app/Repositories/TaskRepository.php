<?php

namespace App\Repositories;

use App\Models\Task;
use App\Models\TaskObserver;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TaskRepository extends BaseRepository
{
    private const OVERDUE_STATUS_IDS = [1, 2];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $request = new \Illuminate\Http\Request($filters);

        return $this->getFilteredTasks($request);
    }

    /**
     * @param  \Illuminate\Http\Request|mixed  $request
     */
    public function getFilteredTasks($request): LengthAwarePaginator
    {
        $companyId = $this->getCurrentCompanyId();
        $query = Task::with(['creator', 'supervisor', 'executor', 'observers', 'project', 'status'])
            ->where('company_id', $companyId);

        $user = auth('api')->user();
        if ($user && ! $user->is_admin) {
            $this->applyTaskOwnScopeIfNeeded($query, $user);
        }

        if ($request->has('status') && $request->status !== '' && $request->status !== 'all') {
            $query->where('status_id', $request->status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 20);

        return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $request->input('page', 1));
    }

    /**
     * @param  int|string  $id
     */
    public function findById($id): Task
    {
        $companyId = $this->getCurrentCompanyId();
        $task = Task::with(['creator', 'supervisor', 'executor', 'observers', 'project.users', 'status'])
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $user = auth('api')->user();
        if ($user && ! $user->is_admin && $this->shouldApplyResourceOwnScope($user, 'tasks')) {
            if (! $task->userCanView($user)) {
                throw new AccessDeniedHttpException(__('api.common.task_view_forbidden'));
            }
        }

        return $task;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Task
    {
        $observerIds = $data['observer_ids'] ?? null;
        unset($data['observer_ids']);

        $data['creator_id'] = auth()->id();
        $data['priority'] = $data['priority'] ?? 'low';
        $data['complexity'] = $data['complexity'] ?? 'normal';

        if (! isset($data['restrict_visibility'])) {
            $data['restrict_visibility'] = true;
        }

        if (! isset($data['company_id']) || ! $data['company_id']) {
            throw new \Exception(__('api.common.company_id_required'));
        }

        if (! isset($data['status_id']) || ! $data['status_id']) {
            $defaultStatus = TaskStatus::orderBy('id')->first();
            if ($defaultStatus) {
                $data['status_id'] = $defaultStatus->id;
            } else {
                throw new \Exception(__('api.tasks.no_statuses_found'));
            }
        }

        $task = Task::create($data);

        if ($observerIds !== null) {
            $this->syncObservers((int) $task->id, $observerIds);
        }

        return $task->fresh(['creator', 'supervisor', 'executor', 'observers', 'project', 'status']);
    }

    /**
     * @param  int  $id
     * @param  array<string, mixed>  $data
     */
    public function update($id, array $data): Task
    {
        $task = $this->findById($id);

        if (array_key_exists('observer_ids', $data)) {
            $this->syncObservers((int) $task->id, $data['observer_ids'] ?? []);
            unset($data['observer_ids']);
        }

        if (array_key_exists('status_id', $data) && $task->status_id == $data['status_id']) {
            unset($data['status_id']);
        }

        if ($data !== []) {
            $task->update($data);

            return $task->fresh(['creator', 'supervisor', 'executor', 'observers', 'project', 'status']);
        }

        return $task;
    }

    /**
     * @param  int|string  $id
     */
    public function delete($id): bool
    {
        $task = $this->findById($id);

        return $task->delete();
    }

    /**
     * @param  int|string  $id
     */
    public function changeStatus($id, int $statusId): Task
    {
        $task = $this->findById($id);
        if ($task->status_id == $statusId) {
            return $task;
        }
        $task->update(['status_id' => $statusId]);

        return $task->fresh(['creator', 'supervisor', 'executor', 'observers', 'project', 'status']);
    }

    /**
     * Количество просроченных задач (deadline < now), доступных текущему пользователю.
     */
    public function getOverdueCount(): int
    {
        $companyId = $this->getCurrentCompanyId();
        $query = Task::query()
            ->where('company_id', $companyId)
            ->whereNotNull('deadline')
            ->where('deadline', '<', now())
            ->whereIn('status_id', self::OVERDUE_STATUS_IDS);

        $user = auth('api')->user();
        if ($user && ! $user->is_admin) {
            $this->applyTaskOwnScopeIfNeeded($query, $user);
        }

        return (int) $query->count();
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyTaskOwnScopeIfNeeded(Builder $query, User $user): void
    {
        if (! $this->shouldApplyResourceOwnScope($user, 'tasks')) {
            return;
        }

        $this->applyTaskOwnScope($query, $user);
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyTaskOwnScope(Builder $query, User $user): void
    {
        $userId = (int) $user->id;

        $query->where(function ($q) use ($userId) {
            $q->where('creator_id', $userId)
                ->orWhere('supervisor_id', $userId)
                ->orWhere('executor_id', $userId)
                ->orWhereExists(function ($sub) use ($userId) {
                    $sub->selectRaw('1')
                        ->from('task_observers')
                        ->whereColumn('task_observers.task_id', 'tasks.id')
                        ->where('task_observers.user_id', $userId);
                })
                ->orWhere(function ($openQ) use ($userId) {
                    $openQ->where('restrict_visibility', false)
                        ->whereNotNull('project_id')
                        ->whereExists(function ($projectSub) use ($userId) {
                            $projectSub->selectRaw('1')
                                ->from('projects')
                                ->whereColumn('projects.id', 'tasks.project_id')
                                ->where(function ($participantQ) use ($userId) {
                                    $participantQ->where('projects.creator_id', $userId)
                                        ->orWhereExists(function ($puSub) use ($userId) {
                                            $puSub->selectRaw('1')
                                                ->from('project_users')
                                                ->whereColumn('project_users.project_id', 'projects.id')
                                                ->where('project_users.user_id', $userId);
                                        });
                                });
                        });
                });
        });
    }

    /**
     * @param  int  $taskId
     * @param  array<int, mixed>  $userIds
     */
    private function syncObservers(int $taskId, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', array_filter($userIds))));

        $this->syncManyToManyUsers(
            TaskObserver::class,
            'task_id',
            $taskId,
            $userIds,
            ['user_column' => 'user_id']
        );
    }
}
