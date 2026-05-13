<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Warehouse;
use App\Models\Product;
use App\Enums\WhWriteoffReason;
use App\Models\WarehouseStock;
use App\Models\WhWriteoff;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarehouseWriteoffControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected Warehouse $warehouse;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('companies')) {
            $this->markTestSkipped('Таблица companies не существует.');
        }

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_store_warehouse_writeoff_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_writeoffs', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['warehouse_id', 'reason', 'products']);
    }

    public function test_store_warehouse_writeoff_success(): void
    {
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
        ]);

        $data = [
            'warehouse_id' => $this->warehouse->id,
            'reason' => 'defect',
            'note' => 'Test writeoff',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_writeoffs', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Списание создано']);
    }

    public function test_update_warehouse_writeoff_success(): void
    {
        $writeoff = WhWriteoff::factory()->create([
            'warehouse_id' => $this->warehouse->id,
        ]);

        $data = [
            'warehouse_id' => $this->warehouse->id,
            'reason' => 'consumable',
            'note' => 'Updated writeoff',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 20,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/warehouse_writeoffs/{$writeoff->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Списание обновлено']);
    }

    public function test_destroy_warehouse_writeoff_success(): void
    {
        $writeoff = WhWriteoff::factory()->create([
            'warehouse_id' => $this->warehouse->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/warehouse_writeoffs/{$writeoff->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Списание удалено']);
    }

    public function test_index_filters_by_reason_and_exclude_reason(): void
    {
        $returnWriteoff = WhWriteoff::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'creator_id' => $this->adminUser->id,
            'reason' => WhWriteoffReason::ReturnSupplier,
        ]);
        $defectWriteoff = WhWriteoff::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'creator_id' => $this->adminUser->id,
            'reason' => WhWriteoffReason::Defect,
        ]);

        $onlyReturns = $this->actingAsApi($this->adminUser)
            ->getJson('/api/warehouse_writeoffs?reason=return_supplier&per_page=50');

        $onlyReturns->assertStatus(200);
        $ids = collect($onlyReturns->json('data.items'))->pluck('id')->all();
        $this->assertContains($returnWriteoff->id, $ids);
        $this->assertNotContains($defectWriteoff->id, $ids);

        $excludingReturns = $this->actingAsApi($this->adminUser)
            ->getJson('/api/warehouse_writeoffs?exclude_reason=return_supplier&per_page=50');

        $excludingReturns->assertStatus(200);
        $idsEx = collect($excludingReturns->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($returnWriteoff->id, $idsEx);
        $this->assertContains($defectWriteoff->id, $idsEx);

        $reasonOverridesExclude = $this->actingAsApi($this->adminUser)
            ->getJson('/api/warehouse_writeoffs?reason=defect&exclude_reason=return_supplier&per_page=50');

        $reasonOverridesExclude->assertStatus(200);
        $idsBoth = collect($reasonOverridesExclude->json('data.items'))->pluck('id')->all();
        $this->assertContains($defectWriteoff->id, $idsBoth);
        $this->assertNotContains($returnWriteoff->id, $idsBoth);
    }
}

