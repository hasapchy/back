<?php

namespace App\Services;

use App\Enums\ProjectContractStatus;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Services\Timeline\TimelineCache;

class ProjectBudgetService
{
    public function __construct(
        private readonly RoundingService $roundingService,
    ) {}

    /**
     * Пересчитать бюджет проекта по сумме активных контрактов в валюте проекта.
     *
     * @param  int  $projectId
     * @return void
     */
    public function syncForProject(int $projectId): void
    {
        $project = Project::query()
            ->select(['id', 'company_id', 'currency_id', 'budget'])
            ->find($projectId);

        if (! $project) {
            return;
        }

        $budget = $this->calculateBudget($project);

        if ((float) $project->budget === $budget) {
            return;
        }

        Project::query()->whereKey($projectId)->update(['budget' => $budget]);

        CacheService::invalidateProjectsCache();
        TimelineCache::forget('project', $projectId);
    }

    /**
     * @param  Project  $project
     * @return float
     */
    private function calculateBudget(Project $project): float
    {
        if (! $project->currency_id) {
            return 0.0;
        }

        $sum = (float) ProjectContract::query()
            ->where('project_id', $project->id)
            ->where('status', ProjectContractStatus::Active)
            ->where('currency_id', $project->currency_id)
            ->sum('amount');

        return $this->roundingService->roundContractAmountForCompany(
            $project->company_id ? (int) $project->company_id : null,
            $sum
        );
    }
}
