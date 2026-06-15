<?php

namespace Tests\Unit;

use App\Support\Timeline\TimelineHiddenChangeFields;
use PHPUnit\Framework\TestCase;

class TimelineHiddenChangeFieldsTest extends TestCase
{
  /**
   * @dataProvider hiddenFieldProvider
   */
    public function test_should_skip_internal_pricing_fields(string $field): void
    {
        $this->assertTrue(TimelineHiddenChangeFields::shouldSkip($field));
    }

  /**
   * @dataProvider visibleFieldProvider
   */
    public function test_should_not_skip_user_facing_fields(string $field): void
    {
        $this->assertFalse(TimelineHiddenChangeFields::shouldSkip($field));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function hiddenFieldProvider(): array
    {
        return [
            ['def_price'],
            ['rep_total_price'],
            ['orig_amount'],
            ['orig_currency_id'],
            ['orig_unit_price'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function visibleFieldProvider(): array
    {
        return [
            ['price'],
            ['total_price'],
            ['amount'],
            ['paid_amount'],
            ['client_balance_id'],
            ['status_id'],
        ];
    }
}
