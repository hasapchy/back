<?php

use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Инициализирует tenancy для компании, чтобы запросы к tenant-таблицам (departments, chat_participants и т.д.) шли в tenant-БД.
 */
function ensureTenancyForBroadcastChannel(int $companyId): void
{
    $company = Company::find($companyId);
    if ($company && $company->tenant_id) {
        $tenant = Tenant::on('central')->find($company->tenant_id);
        if ($tenant) {
            tenancy()->initialize($tenant);
        }
    }
}

Broadcast::channel('company.{companyId}.chat.{chatId}', function ($user, $companyId, $chatId) {
    $companyId = (int) $companyId;
    $chatId = (int) $chatId;

    ensureTenancyForBroadcastChannel($companyId);

    $central = config('tenancy.database.central_connection', 'central');
    $isCompanyMember = DB::connection($central)->table('company_user')
        ->where('company_id', $companyId)
        ->where('user_id', $user->id)
        ->exists();

    if (! $isCompanyMember) {
        return false;
    }

    $canViewChats = $user->is_admin
        || $user->getAllPermissionsForCompany($companyId)->contains('name', 'chats_view_all');

    if (! $canViewChats) {
        return false;
    }

    $isParticipant = DB::table('chat_participants')
        ->join('chats', 'chat_participants.chat_id', '=', 'chats.id')
        ->where('chats.company_id', $companyId)
        ->where('chat_participants.chat_id', $chatId)
        ->where('chat_participants.user_id', $user->id)
        ->exists();

    if (! $isParticipant) {
        return false;
    }

    return true;
});

// Presence канал для онлайна пользователей компании
Broadcast::channel('company.{companyId}.presence', function ($user, $companyId) {
    $companyId = (int) $companyId;

    ensureTenancyForBroadcastChannel($companyId);

    $central = config('tenancy.database.central_connection', 'central');
    $isCompanyMember = DB::connection($central)->table('company_user')
        ->where('company_id', $companyId)
        ->where('user_id', $user->id)
        ->exists();

    if (! $isCompanyMember) {
        return false;
    }

    $canViewChats = $user->is_admin
        || $user->getAllPermissionsForCompany($companyId)->contains('name', 'chats_view_all');

    if (! $canViewChats) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'surname' => $user->surname,
        'email' => $user->email,
        'photo' => $user->photo,
    ];
});
