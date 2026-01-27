<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\ClientBalance;
use App\Models\Currency;

class LinkTransactionsToClientBalances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:link-to-balances {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Link existing transactions to client default balances';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }
        
        $this->info('Starting to link transactions to client balances...');
        
        $transactions = Transaction::whereNotNull('client_id')
            ->whereNull('client_balance_id')
            ->with(['client', 'currency'])
            ->get();
        
        $this->info("Found {$transactions->count()} transactions to process");
        
        $bar = $this->output->createProgressBar($transactions->count());
        $bar->start();
        
        $linked = 0;
        $skipped = 0;
        $errors = 0;
        
        foreach ($transactions as $transaction) {
            try {
                if (!$transaction->client || !$transaction->currency) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }
                
                $balance = ClientBalance::where('client_id', $transaction->client_id)
                    ->where('currency_id', $transaction->currency_id)
                    ->where('is_default', true)
                    ->first();
                
                if (!$balance) {
                    $balance = ClientBalance::where('client_id', $transaction->client_id)
                        ->where('currency_id', $transaction->currency_id)
                        ->orderBy('id', 'asc')
                        ->first();
                }
                
                if (!$balance) {
                    $defaultBalance = ClientBalance::where('client_id', $transaction->client_id)
                        ->where('is_default', true)
                        ->first();
                    
                    if ($defaultBalance) {
                        if ($defaultBalance->currency_id === $transaction->currency_id) {
                            $balance = $defaultBalance;
                        } else {
                            $defaultCurrency = Currency::where('is_default', true)->first();
                            if ($defaultCurrency && $defaultBalance->currency_id === $defaultCurrency->id) {
                                $balance = $defaultBalance;
                            }
                        }
                    }
                    
                    if (!$balance) {
                        $firstBalance = ClientBalance::where('client_id', $transaction->client_id)
                            ->orderBy('id', 'asc')
                            ->first();
                        if ($firstBalance) {
                            $balance = $firstBalance;
                        }
                    }
                }
                
                if ($balance) {
                    if (!$dryRun) {
                        $transaction->client_balance_id = $balance->id;
                        $transaction->save();
                    }
                    $linked++;
                } else {
                    $skipped++;
                    if (!$dryRun) {
                        $this->warn("Transaction {$transaction->id}: No balance found for client {$transaction->client_id}, currency {$transaction->currency_id}");
                    }
                }
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error processing transaction {$transaction->id}: " . $e->getMessage());
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine();
        
        $this->info("Completed!");
        $this->info("Linked: {$linked}");
        $this->info("Skipped: {$skipped}");
        $this->info("Errors: {$errors}");
        
        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }
        
        return Command::SUCCESS;
    }
}
