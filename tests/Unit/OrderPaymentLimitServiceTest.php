<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\OrderPaymentLimitService;
use Tests\TestCase;

class OrderPaymentLimitServiceTest extends TestCase
{
    /**
     * @return void
     */
    public function test_remaining_default_subtracts_paid_amount(): void
    {
        $order = new Order([
            'def_total_price' => 200,
            'paid_amount' => 80,
        ]);

        $remaining = app(OrderPaymentLimitService::class)->remainingDefault($order);

        $this->assertEqualsWithDelta(120.0, $remaining, 0.0001);
    }

    /**
     * @return void
     */
    public function test_resolve_order_id_from_source_type(): void
    {
        $service = app(OrderPaymentLimitService::class);

        $this->assertSame(15, $service->resolveOrderId(null, 'App\\Models\\Order', 15));
        $this->assertSame(7, $service->resolveOrderId(7, 'App\\Models\\Order', 15));
    }
}
