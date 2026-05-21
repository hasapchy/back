<?php

namespace Tests;

use App\Models\Company;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private static bool $forbiddenTestPatternsChecked = false;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSafeTestingDatabase();
        $this->assertNoForbiddenTestPatterns();
    }

    /**
     * @return void
     */
    protected function assertSafeTestingDatabase(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Tests can run only in testing environment.');
        }
    }

    /**
     * @return void
     */
    private function assertNoForbiddenTestPatterns(): void
    {
        if (self::$forbiddenTestPatternsChecked) {
            return;
        }

        $testsDir = __DIR__;
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testsDir));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (realpath($path) === realpath(__FILE__)) {
                continue;
            }
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            if (str_contains($content, 'markTestSkipped(')) {
                $violations[] = $path;
            }
        }

        self::$forbiddenTestPatternsChecked = true;

        if ($violations !== []) {
            throw new RuntimeException('Forbidden skip helper usage found in tests: '.implode(', ', $violations));
        }
    }

    protected function ensureDefaultCurrencyForCompany(Company $company): Currency
    {
        $existing = Currency::query()
            ->where('company_id', $company->id)
            ->where('is_default', true)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $currency = Currency::factory()->create([
            'company_id' => $company->id,
            'is_default' => true,
            'code' => 'ZZ'.$company->id.'_'.bin2hex(random_bytes(4)),
            'name' => 'Test default',
            'symbol' => '¤',
            'status' => true,
        ]);
        CurrencyHistory::query()->create([
            'currency_id' => $currency->id,
            'company_id' => $company->id,
            'exchange_rate' => 1,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => null,
        ]);

        return $currency;
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
