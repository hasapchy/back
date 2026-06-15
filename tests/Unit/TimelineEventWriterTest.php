<?php

namespace Tests\Unit;

use App\Events\TimelineItemCreated;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Timeline\TimelineEventWriter;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TimelineEventWriterTest extends TestCase
{
    public function test_log_products_summary_writes_activity_and_dispatches_timeline_event(): void
    {
        Event::fake([TimelineItemCreated::class]);

        $company = Company::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'creator_id' => $user->id,
        ]);
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);
        $cash = CashRegister::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['creator_id' => $user->id, 'name' => 'Test product']);

        $sale = Sale::factory()->create([
            'client_id' => $client->id,
            'creator_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'cash_id' => $cash->id,
        ]);

        app(TimelineEventWriter::class)->logProductsSummary(
            $sale,
            [
                'added' => [$product->name],
                'removed' => [],
                'updated' => [],
            ],
            $user->id,
        );

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sale',
            'subject_type' => Sale::class,
            'subject_id' => $sale->id,
            'description' => 'activity_log.sale.products_updated',
        ]);

        $activity = Activity::query()
            ->where('description', 'activity_log.sale.products_updated')
            ->where('subject_id', $sale->id)
            ->firstOrFail();

        $properties = $activity->properties->toArray();
        $this->assertSame([$product->name], $properties['added'] ?? []);

        Event::assertDispatched(TimelineItemCreated::class, function (TimelineItemCreated $event) use ($company, $sale) {
            return $event->companyId === $company->id
                && $event->apiType === 'sale'
                && $event->entityId === $sale->id;
        });
    }

    public function test_log_products_summary_skips_empty_diff(): void
    {
        Event::fake([TimelineItemCreated::class]);

        $sale = Sale::factory()->create();

        app(TimelineEventWriter::class)->logProductsSummary(
            $sale,
            ['added' => [], 'removed' => [], 'updated' => []],
            null,
        );

        $this->assertDatabaseMissing('activity_log', [
            'description' => 'activity_log.sale.products_updated',
            'subject_id' => $sale->id,
        ]);

        Event::assertNotDispatched(TimelineItemCreated::class);
    }
}
