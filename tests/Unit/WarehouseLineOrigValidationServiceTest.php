<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProductUnitToBaseFactorResolver;
use App\Services\RoundingService;
use App\Services\UnitConversionGraphService;
use App\Services\WarehouseLineOrigValidationService;
use Tests\TestCase;

class WarehouseLineOrigValidationServiceTest extends TestCase
{

    public function test_validate_line_accepts_consistent_orig_and_base(): void
    {
        [$product, $box] = $this->productWithBoxConversion();
        $service = $this->makeService();
        $edgesByProduct = ProductUnitConversion::query()
            ->where('product_id', $product->id)
            ->get()
            ->groupBy(static fn ($row) => (int) $row->product_id);
        $productsById = Product::query()->whereKey($product->id)->get()->keyBy('id');

        $message = $service->validateLine([
            'product_id' => $product->id,
            'quantity' => 144,
            'orig_unit_id' => $box->id,
            'orig_quantity' => 12,
        ], $productsById, $edgesByProduct, null);

        $this->assertNull($message);
    }

    public function test_validate_line_rejects_inconsistent_quantity(): void
    {
        [$product, $box] = $this->productWithBoxConversion();
        $service = $this->makeService();
        $edgesByProduct = ProductUnitConversion::query()
            ->where('product_id', $product->id)
            ->get()
            ->groupBy(static fn ($row) => (int) $row->product_id);
        $productsById = Product::query()->whereKey($product->id)->get()->keyBy('id');

        $message = $service->validateLine([
            'product_id' => $product->id,
            'quantity' => 100,
            'orig_unit_id' => $box->id,
            'orig_quantity' => 12,
        ], $productsById, $edgesByProduct, null);

        $this->assertNotNull($message);
    }

    /**
     * @return array{0: Product, 1: Unit}
     */
    private function productWithBoxConversion(): array
    {
        $piece = Unit::create(['name' => 'Piece v '.uniqid(), 'short_name' => 'С€С‚']);
        $box = Unit::create(['name' => 'Box v '.uniqid(), 'short_name' => 'РєРѕСЂ']);
        $product = Product::factory()->create([
            'creator_id' => User::factory()->create()->id,
            'unit_id' => $piece->id,
        ]);
        ProductUnitConversion::create([
            'product_id' => $product->id,
            'parent_unit_id' => $box->id,
            'child_unit_id' => $piece->id,
            'quantity' => '12',
        ]);

        return [$product, $box];
    }

    private function makeService(): WarehouseLineOrigValidationService
    {
        return new WarehouseLineOrigValidationService(
            new ProductUnitToBaseFactorResolver(new UnitConversionGraphService()),
            new RoundingService()
        );
    }
}
