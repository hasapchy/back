<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ProjectBudgetService;
use Illuminate\Console\Command;

class SyncProjectBudgetsCommand extends Command
{
    protected $signature = 'projects:sync-budgets {--project-id= : Sync budget for a single project ID}';

    protected $description = 'Recalculate project budgets from active contracts in project currency';

    /**
     * @return int
     */
    public function handle(ProjectBudgetService $budgetService): int
    {
        $projectId = $this->option('project-id');

        $query = Project::query()->select('id')->orderBy('id');

        if ($projectId !== null && $projectId !== '') {
            $query->where('id', (int) $projectId);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No projects found.');

            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($projects) use ($budgetService, $bar) {
            foreach ($projects as $project) {
                $budgetService->syncForProject((int) $project->id);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Synced budgets for {$total} project(s).");

        return Command::SUCCESS;
    }
}
