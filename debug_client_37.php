<?php

require_once 'vendor/autoload.php';

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

// Инициализируем Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== DEBUG CLIENT 37 ===\n";

$client = Client::find(37);
if ($client) {
    echo "Client exists: " . $client->first_name . " " . $client->last_name . "\n";
    echo "Company ID: " . $client->company_id . "\n";
    
    $balance = ClientBalance::where('client_id', 37)->first();
    if ($balance) {
        echo "ClientBalance table: " . $balance->balance . "\n";
    } else {
        echo "No ClientBalance record found\n";
    }
    
    $transactions = Transaction::where('client_id', 37)->get();
    echo "Transactions count: " . $transactions->count() . "\n";
    
    foreach ($transactions as $t) {
        echo "Transaction " . $t->id . ": amount=" . $t->amount . ", type=" . $t->type . ", is_debt=" . $t->is_debt . ", source_type=" . $t->source_type . "\n";
    }
    
    // Проверим SQL запрос из ClientsRepository
    echo "\n=== SQL QUERY TEST ===\n";
    $sqlResult = DB::select("
        SELECT 
            clients.*,
            (SELECT COALESCE(balance, 0) FROM client_balances WHERE client_id = clients.id LIMIT 1) as balance_amount
        FROM clients 
        WHERE clients.id = 37
    ");
    
    if (!empty($sqlResult)) {
        echo "SQL result balance_amount: " . $sqlResult[0]->balance_amount . "\n";
    } else {
        echo "No SQL result found\n";
    }
    
} else {
    echo "Client 37 not found\n";
}
