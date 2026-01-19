<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sale;

class SaleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $count = rand(1, 5);
        
        Sale::factory()->count($count)->create();
        
        $this->command->info("Created {$count} sales.");
    }
}


