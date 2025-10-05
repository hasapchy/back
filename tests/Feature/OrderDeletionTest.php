<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Client;
use App\Models\CashRegister;
use App\Models\Warehouse;
use App\Models\Currency;
use App\Repositories\OrdersRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $client;
    protected $cashRegister;
    protected $warehouse;
    protected $currency;

    protected function setUp(): void
    {
        parent::setUp();

        // Создаем тестовые данные
        $this->user = User::factory()->create();
        $this->client = Client::factory()->create();
        $this->currency = Currency::factory()->create(['is_default' => true]);
        $this->cashRegister = CashRegister::factory()->create(['currency_id' => $this->currency->id]);
        $this->warehouse = Warehouse::factory()->create();
    }

    public function test_order_deletion_removes_associated_transactions()
    {
        // Создаем заказ
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'cash_id' => $this->cashRegister->id,
            'warehouse_id' => $this->warehouse->id,
            'total_price' => 1000,
        ]);

        // Создаем транзакцию с morphable связью
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'cash_id' => $this->cashRegister->id,
            'amount' => 500,
            'orig_amount' => 500,
            'source_type' => Order::class,
            'source_id' => $order->id,
        ]);

        // Проверяем, что транзакция существует с morphable связью
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'source_type' => Order::class,
            'source_id' => $order->id,
        ]);

        // Удаляем заказ через репозиторий
        $ordersRepository = new OrdersRepository();
        $ordersRepository->deleteItem($order->id);

        // Проверяем, что заказ удален
        $this->assertDatabaseMissing('orders', [
            'id' => $order->id,
        ]);

        // Проверяем, что транзакция тоже удалена
        $this->assertDatabaseMissing('transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_order_deletion_without_transactions()
    {
        // Создаем заказ без транзакций
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'cash_id' => $this->cashRegister->id,
            'warehouse_id' => $this->warehouse->id,
            'total_price' => 1000,
        ]);

        // Удаляем заказ
        $ordersRepository = new OrdersRepository();
        $ordersRepository->deleteItem($order->id);

        // Проверяем, что заказ удален
        $this->assertDatabaseMissing('orders', [
            'id' => $order->id,
        ]);
    }
}
