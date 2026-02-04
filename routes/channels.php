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
    try {
        $companyId = (int) $companyId;
        $chatId = (int) $chatId;
        if (! DB::table('company_user')->where('company_id', $companyId)->where('user_id', $user->id)->exists()) {
            return false;
        }
        if (! $user->is_admin && ! $user->getAllPermissionsForCompany($companyId)->contains('name', 'chats_view_all')) {
            return false;
        }
        return DB::table('chat_participants')
            ->join('chats', 'chat_participants.chat_id', '=', 'chats.id')
            ->where('chats.company_id', $companyId)
            ->where('chat_participants.chat_id', $chatId)
            ->where('chat_participants.user_id', $user->id)
            ->exists();
    } catch (\Throwable $e) {
        report($e);
        return false;
    }
});

Broadcast::channel('company.{companyId}.orders', function ($user, $companyId) {
    try {
        $companyId = (int) $companyId;
        if (! DB::table('company_user')->where('company_id', $companyId)->where('user_id', $user->id)->exists()) {
            return false;
        }
        if ($user->is_admin) {
            return true;
        }
        $names = $user->getAllPermissionsForCompany($companyId)->pluck('name');
        return $names->contains('orders_view')
            || $names->contains('orders_view_all')
            || $names->contains('orders_simple_view')
            || $names->contains('orders_simple_view_all');
    } catch (\Throwable $e) {
        report($e);
        return false;
    }
});

Broadcast::channel('company.{companyId}.presence', function ($user, $companyId) {
    try {
        $companyId = (int) $companyId;
        if (! DB::table('company_user')->where('company_id', $companyId)->where('user_id', $user->id)->exists()) {
            return false;
        }
        if (! $user->is_admin && ! $user->getAllPermissionsForCompany($companyId)->contains('name', 'chats_view_all')) {
            return false;
        }
        return [
            'id' => $user->id,
            'name' => $user->name,
            'surname' => $user->surname,
            'email' => $user->email,
            'photo' => $user->photo,
        ];
    } catch (\Throwable $e) {
        report($e);
        return false;
    }
});
