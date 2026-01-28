<?php

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

Broadcast::channel('company.{companyId}.chat.{chatId}', function ($user, $companyId, $chatId) {
    $companyId = (int) $companyId;
    $chatId = (int) $chatId;

    $isCompanyMember = DB::table('company_user')
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

    $isCompanyMember = DB::table('company_user')
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
