<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusCategory;
use App\Models\User;
use App\Models\Warehouse;
use Tests\Support\Concerns\SeedsTransactionCategoryBindings;
use Tests\TestCase;

class LeadConversionTest extends TestCase
{
    use SeedsTransactionCategoryBindings;

    protected User $adminUser;

    protected Company $company;

    protected Client $client;

    protected Warehouse $warehouse;

    protected Category $category;

    protected Currency $currency;

    protected CashRegister $cashRegister;

    protected LeadStatus $statusNew;

    protected LeadStatus $statusSuccess;

    protected LeadSource $source;

    protected function setUp(): void
    {
        parent::setUp();


        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->seedStandardTransactionCategoryBindings($this->company, $this->adminUser);
        $this->ensureDefaultOrderStatusExists();

        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->category = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->currency = $this->ensureDefaultCurrencyForCompany($this->company);
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
        ]);

        $this->statusNew = LeadStatus::query()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'name' => 'Новый',
            'color' => '#207ac7',
            'is_active' => true,
            'sort' => 0,
            'kanban_outcome' => null,
        ]);
        $this->statusSuccess = LeadStatus::query()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'name' => 'Успех',
            'color' => '#6c757d',
            'is_active' => true,
            'sort' => 10,
            'kanban_outcome' => 'success',
        ]);
        $this->source = LeadSource::query()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'name' => 'Звонок',
        ]);
    }

    private function ensureDefaultOrderStatusExists(): void
    {
        OrderStatusCategory::query()->updateOrCreate(
            ['id' => 1],
            ['name' => 'NEW', 'creator_id' => $this->adminUser->id, 'color' => '#207ac7']
        );
        OrderStatus::query()->updateOrCreate(
            ['id' => 1],
            ['name' => 'NEW', 'category_id' => 1, 'is_active' => true]
        );
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    public function test_lead_move_to_success_creates_order_once(): void
    {
        $response = $this->actingAsApi($this->adminUser)->postJson('/api/leads', [
            'client_id' => $this->client->id,
            'status_id' => $this->statusNew->id,
            'comment' => 'Тестовый комментарий',
            'files' => ['https://example.com/a.pdf'],
        ]);
        $response->assertStatus(200);
        $leadId = (int) $response->json('data.id');
        $this->assertSame(['https://example.com/a.pdf'], $response->json('data.files'));

        $response2 = $this->actingAsApi($this->adminUser)->putJson("/api/leads/{$leadId}", [
            'status_id' => $this->statusSuccess->id,
        ]);
        $response2->assertStatus(200);
        $orderId = $response2->json('data.order_id');
        $this->assertNotNull($orderId);
        $this->assertDatabaseHas('orders', ['id' => $orderId]);

        $lead = Lead::query()->findOrFail($leadId);
        $this->assertSame((int) $orderId, (int) $lead->order_id);

        $response3 = $this->actingAsApi($this->adminUser)->putJson("/api/leads/{$leadId}", [
            'comment' => 'Обновление без смены статуса',
        ]);
        $response3->assertStatus(200);
        $this->assertSame((int) $orderId, (int) $response3->json('data.order_id'));
        $this->assertSame(1, Order::query()->where('client_id', $this->client->id)->where('id', $orderId)->count());
    }
}
