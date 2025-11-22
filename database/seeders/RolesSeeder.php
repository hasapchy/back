<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Repositories\RolesRepository;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $rolesRepository = app(RolesRepository::class);

        $companies = Company::all();

        if ($companies->isEmpty()) {
            echo "No companies found. Roles will be created when companies are created.\n";
            return;
        }

        foreach ($companies as $company) {
            $rolesRepository->createDefaultRolesForCompany($company->id);
            echo "Roles created for company: {$company->name} (ID: {$company->id})\n";
        }

        echo "Roles created successfully for " . $companies->count() . " companies.\n";
    }
}
