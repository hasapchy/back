<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Transaction;

class TransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $count = rand(1, 5);
        
        Transaction::factory()->count($count)->create();
        
        $this->command->info("Created {$count} transactions.");
    }
}


