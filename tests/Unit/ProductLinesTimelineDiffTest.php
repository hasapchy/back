<?php

namespace Tests\Unit;

use App\Models\WhReceiptProduct;
use App\Services\Timeline\ProductLinesTimelineDiff;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProductLinesTimelineDiffTest extends TestCase
{
    public function test_build_summary_detects_added_removed_and_updated_lines(): void
    {
        $existing = new WhReceiptProduct([
            'product_id' => 1,
            'quantity' => 2,
            'price' => 10,
        ]);
        $removed = new WhReceiptProduct([
            'product_id' => 2,
            'quantity' => 1,
            'price' => 5,
        ]);

        $summary = app(ProductLinesTimelineDiff::class)->buildSummary(
            new Collection([$existing, $removed]),
            [
                ['product_id' => 1, 'quantity' => 3, 'price' => 10],
                ['product_id' => 3, 'quantity' => 1, 'price' => 7],
            ],
            fn ($line, array $incoming) => abs((float) $line->quantity - (float) $incoming['quantity']) > 0.0001,
        );

        $this->assertSame(['1'], $summary['updated']);
        $this->assertSame(['3'], $summary['added']);
        $this->assertSame(['2'], $summary['removed']);
    }
}
