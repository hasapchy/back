<?php

namespace Tests;

use App\Models\Company;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\CacheKeyRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use DatabaseTransactions;
    use \Tests\Support\Concerns\ActsAsApiUser;
    private static bool $forbiddenTestPatternsChecked = false;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertNoForbiddenTestPatterns();
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

            $forbiddenPatterns = [
                'markTestSkipped(',
                'RefreshDatabase',
                'LazilyRefreshDatabase',
                'DatabaseMigrations',
                'migrate:fresh',
                'migrateFreshUsing',
                'Schema::drop',
            ];

            foreach ($forbiddenPatterns as $forbiddenPattern) {
                if (str_contains($content, $forbiddenPattern)) {
                    $violations[] = $path.' :: '.$forbiddenPattern;
                    break;
                }
            }
        }

        self::$forbiddenTestPatternsChecked = true;

        if ($violations !== []) {
            throw new RuntimeException('Forbidden destructive test pattern found in tests: '.implode(', ', $violations));
        }
    }

    /**
     * @return array{0: Company, 1: User}
     */
    protected function createCompanyWithAdminUser(): array
    {
        $company = Company::factory()->create();
        $adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $adminUser->companies()->attach($company->id);

        return [$company, $adminUser];
    }

    /**
     * @return void
     */
    protected function assertForbiddenCrossCompanyAccess(
        \Illuminate\Testing\TestResponse $response
    ): void {
        $response->assertStatus(403);
    }

    protected function ensureDefaultCurrencyForCompany(Company $company): Currency
    {
        $existing = Currency::query()
            ->where('company_id', $company->id)
            ->where('is_default', true)
            ->first();
        if ($existing !== null) {
            if (! $existing->is_report) {
                $existing->forceFill(['is_report' => true])->save();
            }
            $this->ensureCurrencyHistoryForCompany($existing, $company);

            return $existing;
        }

        $currency = Currency::factory()->create([
            'company_id' => $company->id,
            'is_default' => true,
            'is_report' => true,
            'code' => 'ZZ'.$company->id.'_'.bin2hex(random_bytes(4)),
            'name' => 'Test default',
            'symbol' => '¤',
            'status' => true,
        ]);
        $this->ensureCurrencyHistoryForCompany($currency, $company);

        return $currency;
    }

    protected function ensureCurrencyHistoryForCompany(Currency $currency, Company $company): void
    {
        $exists = CurrencyHistory::query()
            ->where('currency_id', $currency->id)
            ->where('company_id', $company->id)
            ->exists();
        if ($exists) {
            return;
        }

        CurrencyHistory::query()->create([
            'currency_id' => $currency->id,
            'company_id' => $company->id,
            'exchange_rate' => 1,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => null,
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

    /**
     * @return void
     */
    protected function flushApplicationCache(): void
    {
        Cache::flush();
        CacheKeyRegistry::clear();
    }

    /**
     * @param  mixed  $value
     */
    protected function putCache(string $key, $value, int $ttl = 3600): void
    {
        Cache::put($key, $value, $ttl);

        if (config('cache.default') === 'file') {
            CacheKeyRegistry::register($key);
        }
    }
}
