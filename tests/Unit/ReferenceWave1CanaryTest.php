<?php

namespace Tests\Unit;

use App\Support\ReferenceWave1Canary;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ReferenceWave1CanaryTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $configSnapshot = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->configSnapshot = [
            'features.reference_wave1' => config('features.reference_wave1'),
            'reference_contracts.canary' => config('reference_contracts.canary'),
        ];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->configSnapshot as $key => $value) {
            Config::set($key, $value);
        }
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function test_returns_false_when_wave1_disabled(): void
    {
        Config::set('features.reference_wave1', false);
        Config::set('reference_contracts.canary', [
            'enabled' => false,
            'company_ids' => [],
            'unscoped_reference_all' => true,
        ]);

        $this->assertFalse(ReferenceWave1Canary::useReferenceAllPayload(1));
        $this->assertFalse(ReferenceWave1Canary::useReferenceAllPayload(null));
    }

    /**
     * @return void
     */
    public function test_returns_true_when_canary_disabled(): void
    {
        Config::set('features.reference_wave1', true);
        Config::set('reference_contracts.canary', [
            'enabled' => false,
            'company_ids' => [1, 2],
            'unscoped_reference_all' => true,
        ]);

        $this->assertTrue(ReferenceWave1Canary::useReferenceAllPayload(99));
    }

    /**
     * @return void
     */
    public function test_scoped_company_must_be_in_list_when_canary_enabled(): void
    {
        Config::set('features.reference_wave1', true);
        Config::set('reference_contracts.canary', [
            'enabled' => true,
            'company_ids' => [1, 2],
            'unscoped_reference_all' => true,
        ]);

        $this->assertTrue(ReferenceWave1Canary::useReferenceAllPayload(1));
        $this->assertFalse(ReferenceWave1Canary::useReferenceAllPayload(3));
    }

    /**
     * @return void
     */
    public function test_scoped_returns_false_when_canary_enabled_but_company_ids_empty(): void
    {
        Config::set('features.reference_wave1', true);
        Config::set('reference_contracts.canary', [
            'enabled' => true,
            'company_ids' => [],
            'unscoped_reference_all' => true,
        ]);

        $this->assertFalse(ReferenceWave1Canary::useReferenceAllPayload(1));
    }

    /**
     * @return void
     */
    public function test_unscoped_respects_unscoped_reference_all_flag(): void
    {
        Config::set('features.reference_wave1', true);
        Config::set('reference_contracts.canary', [
            'enabled' => true,
            'company_ids' => [1],
            'unscoped_reference_all' => false,
        ]);

        $this->assertFalse(ReferenceWave1Canary::useReferenceAllPayload(null));

        Config::set('reference_contracts.canary.unscoped_reference_all', true);

        $this->assertTrue(ReferenceWave1Canary::useReferenceAllPayload(null));
    }
}
