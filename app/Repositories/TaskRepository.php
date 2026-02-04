<?php

namespace App\Repositories;

use App\Models\Task;
use Illuminate\Pagination\LengthAwarePaginator;

class TaskRepository extends BaseRepository
{
    public function getFilteredTasks($request): LengthAwarePaginator
    {
        $companyId = $this->getCurrentCompanyId();
        $query = Task::with(['creator', 'supervisor', 'executor', 'project', 'status'])
                    ->where('company_id', $companyId);

        $user = auth('api')->user();
        if ($user && !$user->is_admin) {
            $permissions = $this->getUserPermissionsForCompany($user);
            $hasViewAll = in_array('tasks_view_all', $permissions);
            $hasViewOwn = in_array('tasks_view_own', $permissions);

            if (!$hasViewAll && $hasViewOwn) {
                $query->where(function($q) use ($user) {
                    $q->where('creator_id', $user->id)
                      ->orWhere('supervisor_id', $user->id)
                      ->orWhere('executor_id', $user->id);
                });
            }
        }

        // Фильтр по статусу (status_id)
        if ($request->has('status') && $request->status !== '' && $request->status !== 'all') {
            $query->where('status_id', $request->status);
        }

        // Фильтр по дате
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Поиск по названию/описанию
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 20);
        return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $request->input('page', 1));
    }

    public function findById($id): Task
    {
        $companyId = $this->getCurrentCompanyId();
        $task = Task::with(['creator', 'supervisor', 'executor', 'project', 'status'])
                    ->where('company_id', $companyId)
                    ->findOrFail($id);

        $user = auth('api')->user();
        if ($user && !$user->is_admin) {
            $permissions = $this->getUserPermissionsForCompany($user);
            $hasViewAll = in_array('tasks_view_all', $permissions);
            $hasViewOwn = in_array('tasks_view_own', $permissions);

            if (!$hasViewAll && $hasViewOwn) {
                $isOwnTask = $task->creator_id === $user->id 
                          || $task->supervisor_id === $user->id 
                          || $task->executor_id === $user->id;
                
                if (!$isOwnTask) {
                    abort(403, 'You do not have permission to view this task');
                }
            }
        }

        return $task;
    }

    public function create(array $data): Task
    {
        $data['creator_id'] = auth()->id();
        // company_id уже должен быть в $data

        $data['priority'] = $data['priority'] ?? 'low';
        $data['complexity'] = $data['complexity'] ?? 'normal';


        if (!isset($data['company_id']) || !$data['company_id']) {
            throw new \Exception('Company ID is required');
        }

        // Если status_id не указан, устанавливаем первый доступный статус по умолчанию
        if (!isset($data['status_id']) || !$data['status_id']) {
            $defaultStatus = \App\Models\TaskStatus::orderBy('id')->first();
            if ($defaultStatus) {
                $data['status_id'] = $defaultStatus->id;
            } else {
                throw new \Exception('No task statuses found. Please create at least one task status.');
            }
        }

        return Task::create($data);
    }

    public function update($id, array $data): Task
    {
        $task = $this->findById($id);
        $task->update($data);

        return $task;
    }

    public function delete($id): bool
    {
        $task = $this->findById($id);
        return $task->delete();
    }

    public function changeStatus($id, int $statusId): Task
    {
        $task = $this->findById($id);
        $task->update(['status_id' => $statusId]);

        return $task;
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
            ->where('deadline', '<', now());

        $user = auth('api')->user();
        if ($user && !$user->is_admin) {
            $permissions = $this->getUserPermissionsForCompany($user);
            $hasViewAll = in_array('tasks_view_all', $permissions);
            $hasViewOwn = in_array('tasks_view_own', $permissions);

            if (!$hasViewAll && $hasViewOwn) {
                $query->where(function ($q) use ($user) {
                    $q->where('creator_id', $user->id)
                        ->orWhere('supervisor_id', $user->id)
                        ->orWhere('executor_id', $user->id);
                });
            }
        }

        return (int) $query->count();
    }
}
