<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $count = rand(1, 5);
        
        Order::factory()->count($count)->create();
        
        $this->command->info("Created {$count} orders.");
    }
}

