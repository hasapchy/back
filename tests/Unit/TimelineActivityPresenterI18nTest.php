<?php

namespace Tests\Unit;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\Timeline\TimelineActivityPresenter;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TimelineActivityPresenterI18nTest extends TestCase
{
    public function test_empty_description_derives_transaction_created_key(): void
    {
        $log = new Activity([
            'log_name' => 'transaction',
            'event' => 'created',
            'description' => '',
            'properties' => new Collection(['attrs' => ['amount' => 100]]),
        ]);
        $log->subject = new Transaction(['id' => 1, 'amount' => 100]);

        $item = app(TimelineActivityPresenter::class)->processActivityLog($log, Transaction::class);

        $this->assertSame('activity_log.transaction.created', $item['description_key']);
    }

    public function test_legacy_properties_still_produce_changes(): void
    {
        $log = new Activity([
            'log_name' => 'transaction',
            'event' => 'updated',
            'description' => 'activity_log.transaction.updated',
            'properties' => new Collection([
                'attributes' => ['amount' => 100],
                'old' => ['amount' => 50],
            ]),
        ]);
        $log->subject = new Transaction(['id' => 1]);

        $item = app(TimelineActivityPresenter::class)->processActivityLog($log, Transaction::class);

        $this->assertNotNull($item['changes']);
        $this->assertArrayHasKey('attributes', $item['changes']);
    }

    public function test_deleted_with_attrs_has_no_changes(): void
    {
        $log = new Activity([
            'log_name' => 'transaction',
            'event' => 'deleted',
            'description' => 'activity_log.transaction.deleted',
            'properties' => new Collection(['attrs' => ['amount' => 50]]),
        ]);
        $log->subject = new Transaction(['id' => 1]);

        $item = app(TimelineActivityPresenter::class)->processActivityLog($log, Transaction::class);

        $this->assertNull($item['changes']);
    }

    public function test_empty_description_order_created_uses_numbered_key(): void
    {
        $order = new Order(['id' => 42, 'creator_id' => 1]);
        $log = new Activity([
            'log_name' => 'order',
            'event' => 'created',
            'description' => '',
            'subject_type' => Order::class,
            'subject_id' => 42,
            'properties' => new Collection(['attrs' => ['client_id' => 1]]),
        ]);
        $log->setRelation('subject', $order);

        $item = app(TimelineActivityPresenter::class)->processActivityLog($log, Order::class);

        $this->assertSame('activity_log.order.created_numbered', $item['description_key']);
        $this->assertSame(42, $item['description_params']['id']);
    }

    public function test_inventory_items_counted_description_params(): void
    {
        $inventory = new Inventory(['id' => 7]);
        $log = new Activity([
            'log_name' => 'inventory',
            'event' => 'updated',
            'description' => 'activity_log.inventory.items_counted',
            'properties' => new Collection([
                'counted' => 50,
                'with_discrepancy' => 3,
            ]),
        ]);
        $log->setRelation('subject', $inventory);

        $item = app(TimelineActivityPresenter::class)->processActivityLog($log, Inventory::class);

        $this->assertSame('activity_log.inventory.items_counted', $item['description_key']);
        $this->assertSame('50', $item['description_params']['counted']);
        $this->assertSame('3', $item['description_params']['with_discrepancy']);
    }

    public function test_order_changes_exclude_def_and_rep_fields(): void
    {
        $log = new Activity([
            'log_name' => 'order',
            'event' => 'updated',
            'description' => 'activity_log.order.updated',
            'properties' => new Collection([
                'attributes' => [
                    'price' => '400.00000',
                    'def_price' => '400.00000',
                    'rep_total_price' => '21.00000',
                ],
                'old' => [
                    'price' => '320.00000',
                    'def_price' => '320.00000',
                    'rep_total_price' => '20.00000',
                ],
            ]),
        ]);
        $log->subject = new Order(['id' => 1]);

        $item = app(TimelineActivityPresenter::class)->processActivityLog($log, Order::class);

        $this->assertNotNull($item['changes']);
        $this->assertArrayHasKey('price', $item['changes']['attributes']);
        $this->assertArrayNotHasKey('def_price', $item['changes']['attributes']);
        $this->assertArrayNotHasKey('rep_total_price', $item['changes']['attributes']);
        $this->assertArrayNotHasKey('def_price', $item['changes']['old']);
        $this->assertArrayNotHasKey('rep_total_price', $item['changes']['old']);
    }

    public function test_order_changes_exclude_orig_fields(): void
    {
        $log = new Activity([
            'log_name' => 'order',
            'event' => 'updated',
            'description' => 'activity_log.order.updated',
            'properties' => new Collection([
                'attributes' => [
                    'price' => '400.00000',
                    'orig_price' => '400.00000',
                    'orig_currency_id' => 1,
                ],
                'old' => [
                    'price' => '320.00000',
                    'orig_price' => '320.00000',
                    'orig_currency_id' => 1,
                ],
            ]),
        ]);
        $log->subject = new Order(['id' => 1]);

        $item = app(TimelineActivityPresenter::class)->processActivityLog($log, Order::class);

        $this->assertNotNull($item['changes']);
        $this->assertArrayHasKey('price', $item['changes']['attributes']);
        $this->assertArrayNotHasKey('orig_price', $item['changes']['attributes']);
        $this->assertArrayNotHasKey('orig_currency_id', $item['changes']['attributes']);
    }
}
