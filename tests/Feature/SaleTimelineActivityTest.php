<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\SalesRepository;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Concerns\ActsAsApiUser;
use Tests\Support\Concerns\SeedsTransactionCategoryBindings;
use Tests\TestCase;

class SaleTimelineActivityTest extends TestCase
{
    use ActsAsApiUser;
    use SeedsTransactionCategoryBindings;

    protected User $adminUser;

    protected Company $company;

    protected Client $client;

    protected Warehouse $warehouse;

    protected CashRegister $cashRegister;

    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->currency = $this->ensureDefaultCurrencyForCompany($this->company);
        $this->seedStandardTransactionCategoryBindings($this->company, $this->adminUser);
        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
        ]);
    }

    public function test_update_sale_with_changed_products_writes_header_and_products_updated_logs(): void
    {
        $productA = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
            'name' => 'Product A',
            'type' => false,
        ]);
        $productB = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
            'name' => 'Product B',
            'type' => false,
        ]);

        $this->actingAsApi($this->adminUser, $this->company);

        $sale = app(SalesRepository::class)->createItem([
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'type' => 'cash',
            'discount' => 0,
            'discount_type' => 'percent',
            'date' => now(),
            'note' => 'initial',
            'products' => [
                [
                    'product_id' => $productA->id,
                    'quantity' => 2,
                    'price' => 100,
                ],
            ],
            'currency_id' => $this->currency->id,
        ]);

        Activity::query()->where('subject_type', Sale::class)->where('subject_id', $sale->id)->delete();

        app(SalesRepository::class)->updateItem($sale->id, [
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'type' => 'cash',
            'discount' => 0,
            'discount_type' => 'percent',
            'date' => now(),
            'note' => 'updated',
            'products' => [
                [
                    'product_id' => $productA->id,
                    'quantity' => 3,
                    'price' => 100,
                ],
                [
                    'product_id' => $productB->id,
                    'quantity' => 1,
                    'price' => 50,
                ],
            ],
            'currency_id' => $this->currency->id,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sale',
            'subject_type' => Sale::class,
            'subject_id' => $sale->id,
            'description' => 'activity_log.sale.updated',
        ]);

        $productsUpdated = Activity::query()
            ->where('description', 'activity_log.sale.products_updated')
            ->where('subject_id', $sale->id)
            ->first();

        $this->assertNotNull($productsUpdated);
        $properties = $productsUpdated->properties->toArray();
        $this->assertContains('Product B', $properties['added'] ?? []);
        $this->assertContains('Product A', $properties['updated'] ?? []);

        $this->assertDatabaseHas('sales_products', [
            'sale_id' => $sale->id,
            'product_id' => $productB->id,
        ]);
    }
}
