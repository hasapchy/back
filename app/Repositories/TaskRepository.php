<?php

namespace App\Repositories;

use App\Models\Task;
use Illuminate\Pagination\LengthAwarePaginator;

class TaskRepository
{
    public function getFilteredTasks($request): LengthAwarePaginator
    {
        $query = Task::with(['creator', 'supervisor', 'executor', 'project'])
                    ->where('company_id', auth()->user()->company_id);

        // Фильтр по статусу
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
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
        return Task::where('company_id', auth()->user()->company_id)->findOrFail($id);
    }

    public function create(array $data): Task
    {
        $data['creator_id'] = auth()->id();
        $data['company_id'] = auth()->user()->company_id;

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

    public function changeStatus($id, string $status): Task
    {
        $task = $this->findById($id);
        $task->update(['status' => $status]);

        return $task;
    }
}
