<?php

namespace Tests\Feature;

use App\Enums\WhWriteoffReason;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Category $category;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('inventories')) {
            $this->markTestSkipped('Таблица inventories не существует.');
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
        $this->category = Category::factory()->create([
            'creator_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
        ]);
        $this->product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => 1,
        ]);
        $this->product->categories()->attach($this->category->id);
        $this->ensureProductPurchasePrice((int) $this->product->id);
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 25,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    protected function postNewInventoryId(): int
    {
        $r = $this->actingAsApi($this->adminUser)->postJson('/api/inventories', [
            'warehouse_id' => $this->warehouse->id,
            'category_ids' => [$this->category->id],
        ]);

        $r->assertOk();

        return (int) $r->json('data.id');
    }

    protected function finalizeInventory(int $id): void
    {
        $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$id}/finalize", [])->assertOk();
    }

    public function test_create_inventory_and_fetch_items(): void
    {
        $inventoryId = $this->postNewInventoryId();

        $itemsResponse = $this->actingAsApi($this->adminUser)->getJson("/api/inventories/{$inventoryId}/items");
        $itemsResponse->assertOk();
        $this->assertNotEmpty($itemsResponse->json('data.items'));
    }

    public function test_bulk_update_and_finalize_inventory(): void
    {
        $inventoryId = $this->postNewInventoryId();

        $itemsResponse = $this->actingAsApi($this->adminUser)->getJson("/api/inventories/{$inventoryId}/items");
        $items = $itemsResponse->json('data.items');
        $this->assertNotEmpty($items);

        $itemId = (int) $items[0]['id'];

        $this->actingAsApi($this->adminUser)->patchJson("/api/inventories/{$inventoryId}/items", [
            'items' => [['id' => $itemId, 'actual_quantity' => 20]],
        ])->assertOk();

        $finalize = $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$inventoryId}/finalize", []);
        $finalize->assertOk();
        $this->assertSame('completed', $finalize->json('data.status'));
    }

    public function test_completed_inventory_items_cannot_be_updated(): void
    {
        $id = $this->postNewInventoryId();
        $itemId = (int) $this->actingAsApi($this->adminUser)
            ->getJson("/api/inventories/{$id}/items")->json('data.items.0.id');

        $this->finalizeInventory($id);

        $update = $this->actingAsApi($this->adminUser)->patchJson("/api/inventories/{$id}/items", [
            'items' => [['id' => $itemId, 'actual_quantity' => 1]],
        ]);

        $update->assertForbidden();
        $update->assertJson(['error' => 'INVENTORY_IMMUTABLE']);
    }

    public function test_completed_inventory_cannot_be_finalized_again(): void
    {
        $id = $this->postNewInventoryId();
        $this->finalizeInventory($id);

        $again = $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$id}/finalize", []);
        $again->assertForbidden();
        $again->assertJson(['error' => 'INVENTORY_IMMUTABLE']);
    }

    public function test_apply_shortage_creates_writeoff_and_links_inventory(): void
    {
        $id = $this->postNewInventoryId();
        $itemId = (int) $this->actingAsApi($this->adminUser)
            ->getJson("/api/inventories/{$id}/items")->json('data.items.0.id');

        $this->actingAsApi($this->adminUser)->patchJson("/api/inventories/{$id}/items", [
            'items' => [['id' => $itemId, 'actual_quantity' => 20]],
        ])->assertOk();

        $this->finalizeInventory($id);

        $apply = $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$id}/apply-shortage", []);
        $apply->assertOk();

        $writeOffId = (int) $apply->json('data.wh_write_off_id');
        $this->assertGreaterThan(0, $writeOffId);

        $wo = WhWriteoff::query()->find($writeOffId);
        $this->assertNotNull($wo);
        $this->assertStringContainsString('Недостача', (string) $wo->note);
        $this->assertSame(WhWriteoffReason::Shortage, $wo->reason);
    }

    public function test_apply_stock_adjustment_creates_writeoff_and_receipt_when_both(): void
    {
        $product2 = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => 1,
        ]);
        $product2->categories()->attach($this->category->id);
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product2->id,
            'quantity' => 40,
        ]);

        $id = $this->postNewInventoryId();
        $items = $this->actingAsApi($this->adminUser)->getJson("/api/inventories/{$id}/items")->json('data.items');
        $this->assertCount(2, $items);
        $byProduct = collect($items)->keyBy(fn ($row) => (int) ($row['product_id'] ?? 0));
        $itemId1 = (int) $byProduct[$this->product->id]['id'];
        $itemId2 = (int) $byProduct[$product2->id]['id'];

        $this->actingAsApi($this->adminUser)->patchJson("/api/inventories/{$id}/items", [
            'items' => [
                ['id' => $itemId1, 'actual_quantity' => 20],
                ['id' => $itemId2, 'actual_quantity' => 45],
            ],
        ])->assertOk();

        $this->finalizeInventory($id);

        $apply = $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$id}/apply-shortage", []);
        $apply->assertOk();

        $writeOffId = (int) $apply->json('data.wh_write_off_id');
        $receiptId = (int) $apply->json('data.wh_receipt_id');
        $this->assertGreaterThan(0, $writeOffId);
        $this->assertGreaterThan(0, $receiptId);
        $wo = WhWriteoff::query()->find($writeOffId);
        $this->assertNotNull($wo);
        $this->assertSame(WhWriteoffReason::Shortage, $wo->reason);
    }

    public function test_apply_shortage_cannot_run_twice(): void
    {
        $id = $this->postNewInventoryId();
        $itemId = (int) $this->actingAsApi($this->adminUser)
            ->getJson("/api/inventories/{$id}/items")->json('data.items.0.id');

        $this->actingAsApi($this->adminUser)->patchJson("/api/inventories/{$id}/items", [
            'items' => [['id' => $itemId, 'actual_quantity' => 18]],
        ])->assertOk();

        $this->finalizeInventory($id);

        $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$id}/apply-shortage", [])->assertOk();
        $second = $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$id}/apply-shortage", []);
        $second->assertStatus(400);
        $this->assertSame('INVENTORY_ADJUSTMENT_ALREADY_APPLIED', $second->json('error'));
    }

    public function test_delete_completed_inventory_removes_linked_writeoff(): void
    {
        $id = $this->postNewInventoryId();
        $itemId = (int) $this->actingAsApi($this->adminUser)
            ->getJson("/api/inventories/{$id}/items")->json('data.items.0.id');

        $this->actingAsApi($this->adminUser)->patchJson("/api/inventories/{$id}/items", [
            'items' => [['id' => $itemId, 'actual_quantity' => 22]],
        ])->assertOk();

        $this->finalizeInventory($id);

        $apply = $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$id}/apply-shortage", []);
        $apply->assertOk();
        $writeOffId = (int) $apply->json('data.wh_write_off_id');

        $this->actingAsApi($this->adminUser)->deleteJson("/api/inventories/{$id}")->assertOk();

        $this->assertDatabaseMissing('inventories', ['id' => $id]);
        $this->assertDatabaseMissing('wh_write_offs', ['id' => $writeOffId]);
    }

    public function test_apply_stock_adjustment_creates_receipt_for_overage(): void
    {
        $id = $this->postNewInventoryId();
        $itemId = (int) $this->actingAsApi($this->adminUser)
            ->getJson("/api/inventories/{$id}/items")->json('data.items.0.id');

        $this->actingAsApi($this->adminUser)->patchJson("/api/inventories/{$id}/items", [
            'items' => [['id' => $itemId, 'actual_quantity' => 30]],
        ])->assertOk();

        $this->finalizeInventory($id);

        $apply = $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$id}/apply-shortage", []);
        $apply->assertOk();

        $receiptId = (int) $apply->json('data.wh_receipt_id');
        $this->assertGreaterThan(0, $receiptId);
        $this->assertNull($apply->json('data.wh_write_off_id'));

        $receipt = WhReceipt::query()->find($receiptId);
        $this->assertNotNull($receipt);
        $this->assertStringContainsString('Излишек', (string) $receipt->note);
    }

    public function test_apply_stock_adjustment_no_lines_returns_400(): void
    {
        $id = $this->postNewInventoryId();
        $this->finalizeInventory($id);

        $apply = $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$id}/apply-shortage", []);
        $apply->assertStatus(400);
        $this->assertSame('INVENTORY_NO_ADJUSTMENT', $apply->json('error'));
    }

    public function test_delete_completed_inventory_removes_linked_receipt(): void
    {
        $id = $this->postNewInventoryId();
        $itemId = (int) $this->actingAsApi($this->adminUser)
            ->getJson("/api/inventories/{$id}/items")->json('data.items.0.id');

        $this->actingAsApi($this->adminUser)->patchJson("/api/inventories/{$id}/items", [
            'items' => [['id' => $itemId, 'actual_quantity' => 28]],
        ])->assertOk();

        $this->finalizeInventory($id);

        $apply = $this->actingAsApi($this->adminUser)->postJson("/api/inventories/{$id}/apply-shortage", []);
        $apply->assertOk();
        $receiptId = (int) $apply->json('data.wh_receipt_id');
        $this->assertGreaterThan(0, $receiptId);

        $this->actingAsApi($this->adminUser)->deleteJson("/api/inventories/{$id}")->assertOk();

        $this->assertDatabaseMissing('inventories', ['id' => $id]);
        $this->assertDatabaseMissing('wh_receipts', ['id' => $receiptId]);
    }
}
