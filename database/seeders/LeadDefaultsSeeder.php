<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class LeadDefaultsSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $creatorId = (int) (User::query()->orderBy('id')->value('id') ?: 1);

        foreach (Company::query()->orderBy('id')->get() as $company) {
            $this->seedStatusesForCompany($company->id, $creatorId);
            $this->seedSourcesForCompany($company->id, $creatorId);
        }
    }

    /**
     * @param  int  $companyId
     * @param  int  $creatorId
     * @return void
     */
    private function seedStatusesForCompany(int $companyId, int $creatorId): void
    {
        if (LeadStatus::query()->where('company_id', $companyId)->exists()) {
            return;
        }

        $statusRows = [
            ['sort' => 0, 'name' => 'Новый', 'color' => '#207ac7', 'kanban_outcome' => null],
            ['sort' => 1, 'name' => 'Звонок', 'color' => '#5bc0de', 'kanban_outcome' => null],
            ['sort' => 2, 'name' => 'Встреча', 'color' => '#28a745', 'kanban_outcome' => null],
            ['sort' => 3, 'name' => 'Обсуждение', 'color' => '#ffc107', 'kanban_outcome' => null],
            ['sort' => 4, 'name' => 'Успех', 'color' => '#6c757d', 'kanban_outcome' => 'success'],
            ['sort' => 5, 'name' => 'Провал', 'color' => '#dc3545', 'kanban_outcome' => 'failure'],
        ];

        foreach ($statusRows as $row) {
            LeadStatus::query()->create([
                'company_id' => $companyId,
                'creator_id' => $creatorId,
                'name' => $row['name'],
                'color' => $row['color'],
                'is_active' => true,
                'sort' => $row['sort'],
                'kanban_outcome' => $row['kanban_outcome'],
            ]);
        }
    }

    /**
     * @param  int  $companyId
     * @param  int  $creatorId
     * @return void
     */
    private function seedSourcesForCompany(int $companyId, int $creatorId): void
    {
        if (LeadSource::query()->where('company_id', $companyId)->exists()) {
            return;
        }

        foreach (['Звонок', 'По знакомству', 'Рассылка', 'Веб сайт'] as $name) {
            LeadSource::query()->create([
                'company_id' => $companyId,
                'creator_id' => $creatorId,
                'name' => $name,
            ]);
        }
    }
}
