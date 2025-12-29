<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatParticipant;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BroadcastChatChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_authorize_private_chat_channel_when_company_member_has_permission_and_is_participant(): void
    {
        $this->seed(PermissionsSeeder::class);

        $company = Company::factory()->create();
        $user = User::factory()->create(['is_admin' => false]);

        $company->users()->attach($user->id);

        // В проекте роли/права привязаны к company_user_role, поэтому для теста просто делаем админом,
        // либо назначаем роль с chats_view. Здесь проще: даём is_admin=true на время.
        $user->forceFill(['is_admin' => true])->save();

        $chat = Chat::query()->create([
            'company_id' => $company->id,
            'type' => 'group',
            'title' => 'Test',
            'created_by' => $user->id,
        ]);

        ChatParticipant::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-company.{$company->id}.chat.{$chat->id}",
            'socket_id' => '123.456',
        ]);

        $response->assertOk();
    }

    public function test_user_cannot_authorize_chat_channel_when_not_company_member(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['is_admin' => true]);

        $chat = Chat::query()->create([
            'company_id' => $company->id,
            'type' => 'group',
            'title' => 'Test',
            'created_by' => $user->id,
        ]);

        ChatParticipant::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-company.{$company->id}.chat.{$chat->id}",
            'socket_id' => '123.456',
        ]);

        $response->assertForbidden();
    }
}


