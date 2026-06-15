<?php

namespace Tests\Unit;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderTempProduct;
use App\Models\ProjectContract;
use App\Models\Transaction;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Support\ActivityLog\ActivityPropertiesNormalizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class ActivityLogNoiseReductionTest extends TestCase
{
    public function test_order_product_does_not_use_activity_log_trait(): void
    {
        $traits = class_uses_recursive(OrderProduct::class);

        $this->assertNotContains(\Spatie\Activitylog\Traits\LogsActivity::class, $traits);
    }

    public function test_order_temp_product_does_not_use_activity_log_trait(): void
    {
        $traits = class_uses_recursive(OrderTempProduct::class);

        $this->assertNotContains(\Spatie\Activitylog\Traits\LogsActivity::class, $traits);
    }

    public function test_inventory_item_does_not_log_events(): void
    {
        $model = new InventoryItem;

        $this->assertFalse($model->shouldLogEvent('created'));
        $this->assertFalse($model->shouldLogEvent('updated'));
        $this->assertFalse($model->shouldLogEvent('deleted'));
    }

    public function test_inventory_items_counted_payload_is_custom(): void
    {
        $custom = [
            'counted' => 120,
            'with_discrepancy' => 8,
        ];

        $this->assertTrue(ActivityPropertiesNormalizer::isCustomPayload($custom));
        $this->assertSame($custom, ActivityPropertiesNormalizer::compress($custom, 'updated'));
    }

    public function test_transaction_does_not_log_debt_with_source(): void
    {
        $cases = [
            ['source_type' => Order::class, 'source_id' => 1],
            ['source_type' => ProjectContract::class, 'source_id' => 2],
            ['source_type' => WhReceipt::class, 'source_id' => 3],
        ];

        foreach ($cases as $attributes) {
            $transaction = new Transaction(array_merge([
                'is_debt' => true,
            ], $attributes));

            foreach (['created', 'updated', 'deleted'] as $event) {
                $this->assertFalse(
                    $this->transactionShouldLogEvent($transaction, $event),
                    $attributes['source_type'].' '.$event
                );
            }
        }
    }

    public function test_transaction_logs_standalone_debt_without_source(): void
    {
        $transaction = new Transaction([
            'is_debt' => true,
            'source_type' => null,
            'source_id' => null,
        ]);

        $this->assertTrue($this->transactionShouldLogEvent($transaction, 'created'));
    }

    public function test_transaction_log_attributes_exclude_creator_id(): void
    {
        $reflection = new \ReflectionClass(Transaction::class);
        $property = $reflection->getProperty('logAttributes');
        $property->setAccessible(true);

        /** @var list<string> $attributes */
        $attributes = $property->getValue();

        $this->assertNotContains('creator_id', $attributes);
    }

    public function test_wh_receipt_log_attributes_exclude_orig_fields(): void
    {
        $options = (new WhReceipt)->getActivitylogOptions();
        $attributes = $options->logAttributes ?? [];

        $this->assertContains('amount', $attributes);
        $this->assertNotContains('orig_amount', $attributes);
        $this->assertNotContains('orig_currency_id', $attributes);
    }

    public function test_wh_purchase_log_attributes_exclude_orig_fields(): void
    {
        $options = (new WhPurchase)->getActivitylogOptions();
        $attributes = $options->logAttributes ?? [];

        $this->assertContains('amount', $attributes);
        $this->assertNotContains('orig_amount', $attributes);
        $this->assertNotContains('orig_currency_id', $attributes);
    }

    public function test_order_log_attributes_exclude_def_and_rep_fields(): void
    {
        $reflection = new \ReflectionClass(Order::class);
        $property = $reflection->getProperty('logAttributes');
        $property->setAccessible(true);

        /** @var list<string> $attributes */
        $attributes = $property->getValue();

        $this->assertContains('price', $attributes);
        $this->assertNotContains('def_price', $attributes);
        $this->assertNotContains('rep_total_price', $attributes);
    }

    /**
     * @param Transaction $transaction
     * @param string $eventName
     * @return bool
     */
    private function transactionShouldLogEvent(Transaction $transaction, string $eventName): bool
    {
        $optionsProperty = new ReflectionProperty(Transaction::class, 'activitylogOptions');
        $optionsProperty->setAccessible(true);
        if (! $optionsProperty->isInitialized($transaction)) {
            $optionsProperty->setValue($transaction, $transaction->getActivitylogOptions());
        }

        $method = new ReflectionMethod(Transaction::class, 'shouldLogEvent');
        $method->setAccessible(true);

        return $method->invoke($transaction, $eventName);
    }
}
