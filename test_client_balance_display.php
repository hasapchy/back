<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Repositories\ClientsRepository;
use App\Http\Controllers\Api\ClientController;

echo "=== CLIENT BALANCE DISPLAY TEST ===\n";

$repository = new ClientsRepository();
$controller = new ClientController($repository);

// Тестируем получение клиента с ID 1
echo "Testing ClientController::show(1):\n";
try {
    $response = $controller->show(1);
    $data = json_decode($response->getContent(), true);
    
    if (isset($data['item'])) {
        $client = $data['item'];
        echo "Client ID: " . $client['id'] . "\n";
        echo "Client Name: " . $client['first_name'] . " " . $client['last_name'] . "\n";
        echo "Balance Amount: " . $client['balance_amount'] . "\n";
        echo "Balance Amount Type: " . gettype($client['balance_amount']) . "\n";
        
        // Проверяем, что баланс не равен 0
        if ($client['balance_amount'] != 0) {
            echo "✅ Balance is not zero: " . $client['balance_amount'] . "\n";
        } else {
            echo "❌ Balance is zero!\n";
        }
    } else {
        echo "❌ No client data found\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETED ===\n";
