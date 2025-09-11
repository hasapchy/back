<?php

namespace App\Repositories;

use App\Models\ProjectTransaction;
use Illuminate\Pagination\LengthAwarePaginator;

class ProjectTransactionsRepository
{
    public function getItemsWithPagination($userId, $perPage = 20, $search = null, $dateFilter = null, $startDate = null, $endDate = null, $projectId = null)
    {
        $query = ProjectTransaction::with(['user', 'currency', 'project'])
            ->where('user_id', $userId);

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('note', 'like', "%{$search}%")
                  ->orWhere('amount', 'like', "%{$search}%");
            });
        }

        if ($dateFilter && $dateFilter !== 'all_time') {
            switch ($dateFilter) {
                case 'today':
                    $query->whereDate('date', today());
                    break;
                case 'yesterday':
                    $query->whereDate('date', today()->subDay());
                    break;
                case 'this_week':
                    $query->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'last_week':
                    $query->whereBetween('date', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()]);
                    break;
                case 'this_month':
                    $query->whereMonth('date', now()->month)->whereYear('date', now()->year);
                    break;
                case 'last_month':
                    $query->whereMonth('date', now()->subMonth()->month)->whereYear('date', now()->subMonth()->year);
                    break;
                case 'custom':
                    if ($startDate && $endDate) {
                        $query->whereBetween('date', [$startDate, $endDate]);
                    }
                    break;
            }
        }

        return $query->orderBy('date', 'desc')->paginate($perPage);
    }

    public function createItem($data)
    {
        $item = ProjectTransaction::create($data);

        // Инвалидируем кэш проекта
        if (isset($data['project_id'])) {
            $projectsRepo = new \App\Repositories\ProjectsRepository();
            $projectsRepo->invalidateProjectCache($data['project_id']);
        }

        return $item;
    }

    public function updateItem($id, $data)
    {
        $item = ProjectTransaction::findOrFail($id);
        $item->update($data);

        // Инвалидируем кэш проекта
        $projectsRepo = new \App\Repositories\ProjectsRepository();
        $projectsRepo->invalidateProjectCache($item->project_id);

        return $item;
    }

    public function deleteItem($id)
    {
        $item = ProjectTransaction::findOrFail($id);
        $projectId = $item->project_id;
        $result = $item->delete();

        // Инвалидируем кэш проекта
        if ($result) {
            $projectsRepo = new \App\Repositories\ProjectsRepository();
            $projectsRepo->invalidateProjectCache($projectId);
        }

        return $result;
    }

    public function getItemById($id)
    {
        return ProjectTransaction::with(['user', 'currency'])->find($id);
    }

    public function getTotalAmount($userId, $dateFilter = null, $startDate = null, $endDate = null)
    {
        $query = ProjectTransaction::where('user_id', $userId);

        if ($dateFilter && $dateFilter !== 'all_time') {
            switch ($dateFilter) {
                case 'today':
                    $query->whereDate('date', today());
                    break;
                case 'yesterday':
                    $query->whereDate('date', today()->subDay());
                    break;
                case 'this_week':
                    $query->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'last_week':
                    $query->whereBetween('date', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()]);
                    break;
                case 'this_month':
                    $query->whereMonth('date', now()->month)->whereYear('date', now()->year);
                    break;
                case 'last_month':
                    $query->whereMonth('date', now()->subMonth()->month)->whereYear('date', now()->subMonth()->year);
                    break;
                case 'custom':
                    if ($startDate && $endDate) {
                        $query->whereBetween('date', [$startDate, $endDate]);
                    }
                    break;
            }
        }

        return $query->sum('amount');
    }
}
