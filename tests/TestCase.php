<?php

namespace Tests;

use App\Models\Company;
use App\Models\Currency;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function ensureDefaultCurrencyForCompany(Company $company): Currency
    {
        $existing = Currency::query()
            ->where('company_id', $company->id)
            ->where('is_default', true)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return Currency::factory()->create([
            'company_id' => $company->id,
            'is_default' => true,
            'code' => 'ZZ'.$company->id.'_'.bin2hex(random_bytes(4)),
            'name' => 'Test default',
            'symbol' => '¤',
            'exchange_rate' => 1,
            'status' => true,
        ]);
    }

    protected function ensureProductPurchasePrice(int $productId, float $purchasePrice = 150.0): void
    {
        ProductPrice::query()->updateOrCreate(
            ['product_id' => $productId],
            ['purchase_price' => $purchasePrice, 'retail_price' => 0, 'wholesale_price' => 0]
        );
    }

    /**
     * @return $this
     */
    protected function withApiTokenForCompany(User $user, ?int $companyId): self
    {
        $issued = $user->createToken('test-token', ['*']);
        if ($companyId !== null) {
            $issued->accessToken->forceFill(['company_id' => $companyId])->save();
        }

        return $this->withHeader('Authorization', 'Bearer '.$issued->plainTextToken);
    }
}
