<?php

namespace Tests\Feature;

use App\Enums\ListFilterPresetSource;
use App\Models\Company;
use App\Models\User;
use App\Models\UserFilterPreset;
use App\Support\ListFilterPresetFields;
use Tests\TestCase;

class UserFilterPresetsControllerTest extends TestCase
{
    private const PRESET_ICON = 'fas fa-bookmark';

    private const PRESET_COLOR = '#3571A4';

    protected User $adminUser;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withPresetAppearance(array $payload): array
    {
        return array_merge([
            'icon' => self::PRESET_ICON,
            'color' => self::PRESET_COLOR,
        ], $payload);
    }

    public function test_index_returns_items_and_schema(): void
    {
        UserFilterPreset::query()->create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'source' => 'transactions',
            'name' => 'Долги',
            'icon' => self::PRESET_ICON,
            'color' => self::PRESET_COLOR,
            'filters' => [
                'cashRegisterId' => '',
                'dateFilter' => 'this_month',
                'startDate' => null,
                'endDate' => null,
                'transactionTypeFilter' => '',
                'sourceFilter' => '',
                'projectId' => '',
                'debtFilter' => 'true',
                'categoryFilter' => [],
            ],
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/user/filter-presets?source=transactions');

        $response->assertOk();
        $response->assertJsonPath('data.items.0.name', 'Долги');
        $response->assertJsonStructure([
            'data' => [
                'items',
                'defaultPresetId',
                'schema' => ['keys', 'defaults', 'ignoredKeysInKanban', 'appearance'],
            ],
        ]);
        $response->assertJsonPath('data.defaultPresetId', null);
        $this->assertContains('dateFilter', $response->json('data.schema.keys'));
        $this->assertSame('this_month', $response->json('data.schema.defaults.dateFilter'));
    }

    public function test_orders_schema_includes_ignored_keys_in_kanban(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/user/filter-presets?source=orders');

        $response->assertOk();
        $this->assertSame(['statusFilter'], $response->json('data.schema.ignoredKeysInKanban'));
    }

    public function test_store_merges_defaults_and_rejects_unknown_key(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/user/filter-presets', [
                'source' => 'orders',
                'name' => 'Новые',
                'filters' => [
                    'statusFilter' => '5',
                    'unknownKey' => 'x',
                ],
            ]);

        $response->assertStatus(422);

        $ok = $this->actingAsApi($this->adminUser)
            ->postJson('/api/user/filter-presets', $this->withPresetAppearance([
                'source' => 'orders',
                'name' => 'Новые',
                'filters' => [
                    'statusFilter' => '5',
                ],
            ]));

        $ok->assertStatus(201);
        $ok->assertJsonPath('data.filters.dateFilter', 'all_time');
        $ok->assertJsonPath('data.filters.paidOrdersFilter', false);
        $ok->assertJsonPath('data.filters.statusFilter', '5');
    }

    public function test_store_rejects_duplicate_name(): void
    {
        $payload = $this->withPresetAppearance([
            'source' => 'projects',
            'name' => 'Мои',
            'filters' => ['statusFilter' => '', 'clientFilter' => ''],
        ]);

        $this->actingAsApi($this->adminUser)->postJson('/api/user/filter-presets', $payload)->assertStatus(201);

        $this->actingAsApi($this->adminUser)
            ->postJson('/api/user/filter-presets', $payload)
            ->assertStatus(422);
    }

    public function test_update_rename_and_overwrite_filters(): void
    {
        $create = $this->actingAsApi($this->adminUser)
            ->postJson('/api/user/filter-presets', $this->withPresetAppearance([
                'source' => 'contracts',
                'name' => 'Старый',
                'filters' => ['projectFilter' => '1'],
            ]));
        $create->assertStatus(201);
        $id = $create->json('data.id');

        $update = $this->actingAsApi($this->adminUser)
            ->putJson("/api/user/filter-presets/{$id}", [
                'name' => 'Новый',
                'icon' => 'fas fa-star',
                'color' => '#8B5CF6',
                'filters' => ['paymentStatusFilter' => 'paid'],
            ]);

        $update->assertOk();
        $update->assertJsonPath('data.name', 'Новый');
        $update->assertJsonPath('data.filters.paymentStatusFilter', 'paid');
        $update->assertJsonPath('data.filters.projectFilter', '');
    }

    public function test_delete_other_user_preset_returns_404(): void
    {
        $otherUser = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $otherUser->companies()->attach($this->company->id);

        $preset = UserFilterPreset::query()->create([
            'user_id' => $otherUser->id,
            'company_id' => $this->company->id,
            'source' => 'orders',
            'name' => 'Чужой',
            'icon' => self::PRESET_ICON,
            'color' => self::PRESET_COLOR,
            'filters' => ListFilterPresetFields::defaultsFor(ListFilterPresetSource::Orders),
        ]);

        $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/user/filter-presets/{$preset->id}")
            ->assertStatus(404);
    }

    public function test_store_saves_icon_and_color(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/user/filter-presets', [
                'source' => 'orders',
                'name' => 'Цветной',
                'icon' => 'fas fa-star',
                'color' => '#8B5CF6',
                'filters' => ['statusFilter' => '1'],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.icon', 'fas fa-star');
        $response->assertJsonPath('data.color', '#8B5CF6');
    }

    public function test_store_rejects_invalid_icon(): void
    {
        $this->actingAsApi($this->adminUser)
            ->postJson('/api/user/filter-presets', [
                'source' => 'orders',
                'name' => 'Плохой',
                'icon' => 'fas fa-hacker',
                'color' => self::PRESET_COLOR,
                'filters' => ['statusFilter' => ''],
            ])
            ->assertStatus(422);
    }

    public function test_store_requires_icon_and_color(): void
    {
        $this->actingAsApi($this->adminUser)
            ->postJson('/api/user/filter-presets', [
                'source' => 'orders',
                'name' => 'Без оформления',
                'filters' => ['statusFilter' => ''],
            ])
            ->assertStatus(422);
    }

    public function test_set_default_and_returns_in_index(): void
    {
        $create = $this->actingAsApi($this->adminUser)
            ->postJson('/api/user/filter-presets', $this->withPresetAppearance([
                'source' => 'transactions',
                'name' => 'Мой',
                'filters' => ['debtFilter' => 'true'],
            ]));
        $create->assertStatus(201);
        $presetId = $create->json('data.id');

        $setDefault = $this->actingAsApi($this->adminUser)
            ->putJson('/api/user/filter-presets/default', [
                'source' => 'transactions',
                'preset_id' => $presetId,
            ]);

        $setDefault->assertOk();
        $setDefault->assertJsonPath('data.defaultPresetId', $presetId);

        $index = $this->actingAsApi($this->adminUser)
            ->getJson('/api/user/filter-presets?source=transactions');

        $index->assertOk();
        $index->assertJsonPath('data.defaultPresetId', $presetId);
    }

    public function test_clear_default_preset(): void
    {
        $create = $this->actingAsApi($this->adminUser)
            ->postJson('/api/user/filter-presets', $this->withPresetAppearance([
                'source' => 'orders',
                'name' => 'Заказы',
                'filters' => ['statusFilter' => '1'],
            ]));
        $presetId = $create->json('data.id');

        $this->actingAsApi($this->adminUser)
            ->putJson('/api/user/filter-presets/default', [
                'source' => 'orders',
                'preset_id' => $presetId,
            ])
            ->assertOk();

        $this->actingAsApi($this->adminUser)
            ->putJson('/api/user/filter-presets/default', [
                'source' => 'orders',
                'preset_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.defaultPresetId', null);
    }

    public function test_delete_preset_clears_default(): void
    {
        $create = $this->actingAsApi($this->adminUser)
            ->postJson('/api/user/filter-presets', $this->withPresetAppearance([
                'source' => 'projects',
                'name' => 'Проекты',
                'filters' => ['clientFilter' => '2'],
            ]));
        $presetId = $create->json('data.id');

        $this->actingAsApi($this->adminUser)
            ->putJson('/api/user/filter-presets/default', [
                'source' => 'projects',
                'preset_id' => $presetId,
            ])
            ->assertOk();

        $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/user/filter-presets/{$presetId}")
            ->assertOk();

        $index = $this->actingAsApi($this->adminUser)
            ->getJson('/api/user/filter-presets?source=projects');

        $index->assertOk();
        $index->assertJsonPath('data.defaultPresetId', null);
    }

    public function test_presets_isolated_between_companies(): void
    {
        $otherCompany = Company::factory()->create();
        $this->adminUser->companies()->attach($otherCompany->id);

        UserFilterPreset::query()->create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'source' => 'orders',
            'name' => 'Company A',
            'icon' => self::PRESET_ICON,
            'color' => self::PRESET_COLOR,
            'filters' => [
                'dateFilter' => 'all_time',
                'startDate' => null,
                'endDate' => null,
                'statusFilter' => '',
                'projectFilter' => '',
                'clientFilter' => '',
                'categoryFilter' => '',
                'paidOrdersFilter' => false,
            ],
        ]);

        $response = $this->actingAsApi($this->adminUser, (int) $otherCompany->id)
            ->getJson('/api/user/filter-presets?source=orders');

        $response->assertOk();
        $this->assertSame([], $response->json('data.items'));
    }
}
