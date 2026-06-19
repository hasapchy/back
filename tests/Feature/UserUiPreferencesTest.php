<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Tests\TestCase;

class UserUiPreferencesTest extends TestCase
{
    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->company = Company::factory()->create();
        $this->user->companies()->attach($this->company->id);
    }

    public function test_get_ui_preferences_returns_defaults(): void
    {
        $response = $this->actingAsApi($this->user)
            ->getJson('/api/user/ui-preferences');

        $response->assertStatus(200);
        $response->assertJsonPath('data.preferences.v', 1);
        $response->assertJsonPath('data.preferences.vuex', []);
        $response->assertJsonPath('data.preferences.ls', []);
        $response->assertJsonPath('data.updated_at', null);
    }

    public function test_patch_ui_preferences_merges_vuex_and_ls(): void
    {
        $response = $this->actingAsApi($this->user)
            ->patchJson('/api/user/ui-preferences', [
                'vuex' => [
                    'viewModes' => ['orders' => 'kanban'],
                ],
                'ls' => [
                    'tableColumns_admin.orders_1' => '[{"name":"id"}]',
                    'cardFields_admin.tasks.cards_1' => '{"title":{"visible":true}}',
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.preferences.vuex.viewModes.orders', 'kanban');
        $ls = $response->json('data.preferences.ls');
        $this->assertIsArray($ls);
        $this->assertSame('[{"name":"id"}]', $ls['tableColumns_admin.orders_1'] ?? null);
        $this->assertSame(
            '{"title":{"visible":true}}',
            $ls['cardFields_admin.tasks.cards_1'] ?? null
        );
        $this->assertNotNull($response->json('data.updated_at'));
    }

    public function test_patch_syncs_menu_items_vuex(): void
    {
        $response = $this->actingAsApi($this->user)
            ->patchJson('/api/user/ui-preferences', [
                'vuex' => [
                    'menuItems' => [
                        'main' => [
                            ['id' => 'finance', 'label' => 'finance', 'to' => '/transactions'],
                        ],
                        'available' => [],
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.preferences.vuex.menuItems.main.0.id', 'finance');
        $this->assertNotNull($response->json('data.updated_at'));
    }

    public function test_patch_syncs_finance_cash_modules_layout_and_colors(): void
    {
        $layoutKey = 'ui_transactions_balance_cards_layout_'.$this->company->id;
        $colorsKey = 'ui_cash_register_user_colors_'.$this->user->id.'_'.$this->company->id;
        $layoutJson = '{"order":["cash_1"],"cards":[],"rowsCount":2}';
        $colorsJson = '{"1":{"mode":"custom","color":"#3571A4"}}';

        $response = $this->actingAsApi($this->user)
            ->patchJson('/api/user/ui-preferences', [
                'ls' => [
                    $layoutKey => $layoutJson,
                    $colorsKey => $colorsJson,
                ],
            ]);

        $response->assertStatus(200);
        $ls = $response->json('data.preferences.ls');
        $this->assertIsArray($ls);
        $this->assertSame($layoutJson, $ls[$layoutKey] ?? null);
        $this->assertSame($colorsJson, $ls[$colorsKey] ?? null);
    }

    public function test_patch_rejects_unknown_ls_key(): void
    {
        $response = $this->actingAsApi($this->user)
            ->patchJson('/api/user/ui-preferences', [
                'ls' => [
                    'cashRegisters_1' => '[]',
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_me_includes_ui_preferences_updated_at(): void
    {
        $this->actingAsApi($this->user)
            ->patchJson('/api/user/ui-preferences', [
                'vuex' => ['uiTheme' => 'dark'],
            ])
            ->assertStatus(200);

        $this->user->refresh();

        $response = $this->actingAsApi($this->user, $this->company)
            ->getJson('/api/user/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.ui_preferences_updated_at', $this->user->ui_preferences_updated_at);
    }
}
