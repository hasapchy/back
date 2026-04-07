<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Repositories\RolesRepository;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $rolesRepository = app(RolesRepository::class);

        $companies = Company::all();

        if ($companies->isEmpty()) {
            $this->command?->warn('Компаний нет — роли не созданы.');

            return;
        }

        foreach ($companies as $company) {
            $rolesRepository->createDefaultRolesForCompany($company->id);
        }
    }
}
