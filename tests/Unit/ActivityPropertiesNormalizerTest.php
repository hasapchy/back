<?php

namespace Tests\Unit;

use App\Support\ActivityLog\ActivityPropertiesNormalizer;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityPropertiesNormalizerTest extends TestCase
{
    public function test_compress_updated_to_diff_and_expand_roundtrip(): void
    {
        $legacy = [
            'attributes' => ['amount' => 100, 'client_id' => 5],
            'old' => ['amount' => 50, 'client_id' => 3],
        ];

        $compressed = ActivityPropertiesNormalizer::compress($legacy, 'updated');

        $this->assertSame([
            'diff' => [
                'amount' => ['from' => 50, 'to' => 100],
                'client_id' => ['from' => 3, 'to' => 5],
            ],
        ], $compressed);

        $expanded = ActivityPropertiesNormalizer::expand($compressed);

        $this->assertSame(['amount' => 100, 'client_id' => 5], $expanded['attributes']);
        $this->assertSame(['amount' => 50, 'client_id' => 3], $expanded['old']);
    }

    public function test_compress_created_to_attrs(): void
    {
        $legacy = ['attributes' => ['amount' => 100]];

        $compressed = ActivityPropertiesNormalizer::compress($legacy, 'created');

        $this->assertSame(['attrs' => ['amount' => 100]], $compressed);

        $expanded = ActivityPropertiesNormalizer::expand($compressed);

        $this->assertSame(['amount' => 100], $expanded['attributes']);
        $this->assertNull($expanded['old']);
    }

    public function test_compress_deleted_to_attrs_snapshot(): void
    {
        $legacy = ['old' => ['amount' => 50]];

        $compressed = ActivityPropertiesNormalizer::compress($legacy, 'deleted');

        $this->assertSame(['attrs' => ['amount' => 50]], $compressed);
    }

    public function test_custom_products_updated_payload_is_not_compressed(): void
    {
        $custom = [
            'added' => ['Item A'],
            'removed' => [],
            'updated' => ['Item B'],
        ];

        $compressed = ActivityPropertiesNormalizer::compress($custom, 'updated');

        $this->assertSame($custom, $compressed);
        $this->assertTrue(ActivityPropertiesNormalizer::isCustomPayload($custom));
    }

    public function test_compress_is_idempotent(): void
    {
        $compact = [
            'diff' => [
                'amount' => ['from' => 1, 'to' => 2],
            ],
        ];

        $this->assertSame($compact, ActivityPropertiesNormalizer::compress($compact, 'updated'));
    }

    public function test_should_clear_legacy_russian_crud_description(): void
    {
        $activity = new Activity([
            'log_name' => 'transaction',
            'event' => 'created',
            'description' => 'Создана транзакция',
        ]);

        $this->assertTrue(ActivityPropertiesNormalizer::shouldClearCrudDescription($activity));
    }

    public function test_should_not_clear_custom_description(): void
    {
        $activity = new Activity([
            'log_name' => 'order',
            'event' => 'updated',
            'description' => 'activity_log.order.products_updated',
        ]);

        $this->assertFalse(ActivityPropertiesNormalizer::shouldClearCrudDescription($activity));
    }

    public function test_should_not_clear_description_for_deprecated_log_name(): void
    {
        $activity = new Activity([
            'log_name' => 'order_transaction',
            'event' => 'created',
            'description' => 'Добавлена транзакция #1',
        ]);

        $this->assertFalse(ActivityPropertiesNormalizer::shouldClearCrudDescription($activity));
    }

    public function test_is_derivable_description(): void
    {
        $activity = new Activity([
            'log_name' => 'transaction',
            'event' => 'created',
            'description' => 'activity_log.transaction.created',
        ]);

        $this->assertTrue(ActivityPropertiesNormalizer::isDerivableDescription($activity));

        $activity->description = 'activity_log.order.products_updated';
        $this->assertFalse(ActivityPropertiesNormalizer::isDerivableDescription($activity));

        $activity = new Activity([
            'log_name' => 'project_contract',
            'event' => 'updated',
            'description' => 'activity_log.project_contract.returned_signed',
        ]);

        $this->assertFalse(ActivityPropertiesNormalizer::isDerivableDescription($activity));
    }

    public function test_field_value_reads_legacy_attrs_and_diff(): void
    {
        $this->assertSame(100, ActivityPropertiesNormalizer::fieldValue(
            ['attributes' => ['amount' => 100]],
            'amount'
        ));

        $this->assertSame(50, ActivityPropertiesNormalizer::fieldValue(
            ['attrs' => ['amount' => 50]],
            'amount'
        ));

        $this->assertSame(100, ActivityPropertiesNormalizer::fieldValue(
            ['diff' => ['amount' => ['from' => 50, 'to' => 100]]],
            'amount',
            'to'
        ));

        $this->assertSame(50, ActivityPropertiesNormalizer::fieldValue(
            ['diff' => ['amount' => ['from' => 50, 'to' => 100]]],
            'amount',
            'from'
        ));
    }

    public function test_to_array_accepts_collection(): void
    {
        $collection = new Collection(['attributes' => ['name' => 'Test']]);

        $this->assertSame(['attributes' => ['name' => 'Test']], ActivityPropertiesNormalizer::toArray($collection));
    }

    public function test_derive_description_key_and_order_created_detection(): void
    {
        $activity = new Activity([
            'log_name' => 'transaction',
            'event' => 'created',
            'description' => '',
        ]);

        $this->assertSame('activity_log.transaction.created', ActivityPropertiesNormalizer::deriveDescriptionKey($activity));

        $orderActivity = new Activity([
            'log_name' => 'order',
            'event' => 'created',
            'description' => '',
        ]);

        $this->assertTrue(ActivityPropertiesNormalizer::isOrderCreatedActivity($orderActivity));
    }
}
