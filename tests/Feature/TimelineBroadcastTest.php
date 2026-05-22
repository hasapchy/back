<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Http\Middleware\VerifyCsrfToken;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimelineBroadcastTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_user_can_authorize_timeline_channel_for_scoped_order(): void
    {
        $this->seed(PermissionsSeeder::class);

        $company = Company::factory()->create();
        $user = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $user->companies()->attach($company->id);

        $client = Client::factory()->create(['company_id' => $company->id]);
        $cash = CashRegister::factory()->create(['company_id' => $company->id]);
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'cash_id' => $cash->id,
            'warehouse_id' => null,
            'project_id' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-company.{$company->id}.timeline.order.{$order->id}",
            'socket_id' => '123.456',
        ]);

        $response->assertOk();
    }

    public function test_user_cannot_authorize_timeline_channel_for_foreign_company_order(): void
    {
        $this->seed(PermissionsSeeder::class);

        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $user = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $user->companies()->attach($companyA->id);

        $clientB = Client::factory()->create(['company_id' => $companyB->id]);
        $cashB = CashRegister::factory()->create(['company_id' => $companyB->id]);
        $orderB = Order::factory()->create([
            'client_id' => $clientB->id,
            'cash_id' => $cashB->id,
            'warehouse_id' => null,
            'project_id' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-company.{$companyA->id}.timeline.order.{$orderB->id}",
            'socket_id' => '123.456',
        ]);

        $response->assertForbidden();
    }
}
