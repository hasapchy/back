<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProductUnitToBaseFactorResolver;
use App\Services\UnitConversionGraphService;
use Tests\TestCase;

class ProductUnitToBaseFactorResolverTest extends TestCase
{

    public function test_factor_alternate_to_base_via_conversion_edge(): void
    {
        $piece = Unit::create(['name' => 'Piece '.uniqid(), 'short_name' => 'С€С‚']);
        $box = Unit::create(['name' => 'Box '.uniqid(), 'short_name' => 'РєРѕСЂ']);
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

        $edges = ProductUnitConversion::query()->where('product_id', $product->id)->get();
        $resolver = new ProductUnitToBaseFactorResolver(new UnitConversionGraphService());
        $factor = $resolver->factorAlternateToBase($edges, (int) $piece->id, (int) $box->id);

        $this->assertSame('12', $factor);
        $this->assertSame(0, bccomp('144', bcmul('12', (string) $factor, 5), 5));
    }

    public function test_unknown_unit_returns_null(): void
    {
        $piece = Unit::create(['name' => 'Piece2 '.uniqid(), 'short_name' => 'С€С‚']);
        $other = Unit::create(['name' => 'Other '.uniqid(), 'short_name' => 'РґСЂ']);
        $product = Product::factory()->create([
            'creator_id' => User::factory()->create()->id,
            'unit_id' => $piece->id,
        ]);

        $edges = ProductUnitConversion::query()->where('product_id', $product->id)->get();
        $resolver = new ProductUnitToBaseFactorResolver(new UnitConversionGraphService());
        $factor = $resolver->factorAlternateToBase($edges, (int) $piece->id, (int) $other->id);

        $this->assertNull($factor);
    }
}
