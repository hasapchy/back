<?php

use App\Broadcasting\CompanyPrivateChannel;
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

$userBelongsToCompany = static function ($user, int $companyId): bool {
    return DB::table('company_user')
        ->where('company_id', $companyId)
        ->where('user_id', $user->id)
        ->exists();
};

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('company.{companyId}.chat.{chatId}', function ($user, $companyId, $chatId) use ($userBelongsToCompany) {
    try {
        $companyId = (int) $companyId;
        $chatId = (int) $chatId;
        if (! $userBelongsToCompany($user, $companyId)) {
            return false;
        }
        $permissions = $user->getAllPermissionsForCompany($companyId)->pluck('name');
        $hasChatAccess = $user->is_admin
            || $permissions->contains('chats_view_all')
            || $permissions->contains('chats_view');
        if (! $hasChatAccess) {
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

Broadcast::channel('company.{companyId}.' . CompanyPrivateChannel::SEGMENT_ORDERS, function ($user, $companyId) use ($userBelongsToCompany) {
    try {
        $companyId = (int) $companyId;
        if (! $userBelongsToCompany($user, $companyId)) {
            return false;
        }
        if ($user->is_admin) {
            return true;
        }
        $names = $user->getAllPermissionsForCompany($companyId)->pluck('name');
        return $names->contains('orders_view')
            || $names->contains('orders_view_all')
            || $names->contains('orders_view_own')
            || $names->contains('orders_simple_view')
            || $names->contains('orders_simple_view_all')
            || $names->contains('orders_simple_view_own');
    } catch (\Throwable $e) {
        report($e);
        return false;
    }
});

Broadcast::channel('company.{companyId}.' . CompanyPrivateChannel::SEGMENT_TRANSACTIONS, function ($user, $companyId) use ($userBelongsToCompany) {
    try {
        $companyId = (int) $companyId;
        if (! $userBelongsToCompany($user, $companyId)) {
            return false;
        }
        if ($user->is_admin) {
            return true;
        }
        $names = $user->getAllPermissionsForCompany($companyId)->pluck('name');

        return $names->contains('transactions_view')
            || $names->contains('transactions_view_all');
    } catch (\Throwable $e) {
        report($e);
        return false;
    }
});

Broadcast::channel('company.{companyId}.user.{userId}', function ($user, $companyId, $userId) use ($userBelongsToCompany) {
    try {
        $companyId = (int) $companyId;
        $userId = (int) $userId;
        if ((int) $user->id !== $userId) {
            return false;
        }

        return $userBelongsToCompany($user, $companyId);
    } catch (\Throwable $e) {
        report($e);
        return false;
    }
});

Broadcast::channel('company.{companyId}.presence', function ($user, $companyId) use ($userBelongsToCompany) {
    try {
        $companyId = (int) $companyId;
        if (! $userBelongsToCompany($user, $companyId)) {
            return false;
        }
        $permissions = $user->getAllPermissionsForCompany($companyId)->pluck('name');
        $hasChatAccess = $user->is_admin
            || $permissions->contains('chats_view_all')
            || $permissions->contains('chats_view');
        if (! $hasChatAccess) {
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
