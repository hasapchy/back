<?php

namespace App\Repositories;

use App\Models\Task;
use Illuminate\Pagination\LengthAwarePaginator;

class TaskRepository
{
    protected function getCompanyId()
    {
        return request()->header('X-Company-ID') ?? auth()->user()->company_id ?? null;
    }

    public function getFilteredTasks($request): LengthAwarePaginator
    {
        $companyId = $this->getCompanyId();
        $query = Task::with(['creator', 'supervisor', 'executor', 'project', 'status'])
                    ->where('company_id', $companyId);

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

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    public function findById($id): Task
    {
        $companyId = $this->getCompanyId();
        return Task::with(['creator', 'supervisor', 'executor', 'project', 'status'])
                    ->where('company_id', $companyId)
                    ->findOrFail($id);
    }

    public function create(array $data): Task
    {
        $data['creator_id'] = auth()->id();
        // company_id уже должен быть в $data

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
}
