<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Project;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $count = rand(1, 5);
        
        Project::factory()->count($count)->create();
        
        $this->command->info("Created {$count} projects.");
    }
}

