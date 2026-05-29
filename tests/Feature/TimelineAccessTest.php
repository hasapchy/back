<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class TimelineAccessTest extends TestCase
{

    public function test_timeline_returns_404_for_order_in_other_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $userA = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $userA->companies()->attach($companyA->id);

        $clientB = Client::factory()->create(['company_id' => $companyB->id]);
        $cashB = CashRegister::factory()->create(['company_id' => $companyB->id]);
        $orderB = Order::factory()->create([
            'client_id' => $clientB->id,
            'cash_id' => $cashB->id,
            'warehouse_id' => null,
            'project_id' => null,
        ]);

        $response = $this->withApiTokenForCompany($userA, (int) $companyA->id)
            ->getJson('/api/comments/timeline?type=order&id='.$orderB->id);

        $response->assertStatus(404);
    }

    public function test_store_comment_returns_404_for_order_in_other_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $userA = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $userA->companies()->attach($companyA->id);

        $clientB = Client::factory()->create(['company_id' => $companyB->id]);
        $cashB = CashRegister::factory()->create(['company_id' => $companyB->id]);
        $orderB = Order::factory()->create([
            'client_id' => $clientB->id,
            'cash_id' => $cashB->id,
            'warehouse_id' => null,
            'project_id' => null,
        ]);

        $response = $this->withApiTokenForCompany($userA, (int) $companyA->id)
            ->postJson('/api/comments', [
                'type' => 'order',
                'id' => $orderB->id,
                'body' => 'Should not be stored',
            ]);

        $response->assertStatus(404);
    }

    public function test_unread_counts_ignores_entities_from_other_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $userA = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $userA->companies()->attach($companyA->id);

        $clientA = Client::factory()->create(['company_id' => $companyA->id]);
        $cashA = CashRegister::factory()->create(['company_id' => $companyA->id]);
        $orderA = Order::factory()->create([
            'client_id' => $clientA->id,
            'cash_id' => $cashA->id,
            'warehouse_id' => null,
            'project_id' => null,
        ]);

        $clientB = Client::factory()->create(['company_id' => $companyB->id]);
        $cashB = CashRegister::factory()->create(['company_id' => $companyB->id]);
        $orderB = Order::factory()->create([
            'client_id' => $clientB->id,
            'cash_id' => $cashB->id,
            'warehouse_id' => null,
            'project_id' => null,
        ]);

        $response = $this->withApiTokenForCompany($userA, (int) $companyA->id)
            ->postJson('/api/comments/timeline/unread-counts', [
                'type' => 'order',
                'ids' => [$orderA->id, $orderB->id],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.counts.'.$orderA->id, 0);
        $response->assertJsonMissingPath('data.counts.'.$orderB->id);
    }
}
