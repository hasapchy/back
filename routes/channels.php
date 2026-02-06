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
 * Инициализирует tenancy для компании, чтобы запросы к tenant-таблицам (chat_participants, chats и т.д.) шли в tenant-БД.
 */
if (! function_exists('ensureTenancyForBroadcastChannel')) {
    function ensureTenancyForBroadcastChannel(int $companyId): void
    {
        $company = Company::on('central')->find($companyId);

    if ($company && $company->tenant_id) {
        $tenant = Tenant::on('central')->find($company->tenant_id);
        if ($tenant) {
            tenancy()->initialize($tenant);
        }
    }
    }
}

Broadcast::channel('company.{companyId}.chat.{chatId}', function ($user, $companyId, $chatId) {
    try {
        $companyId = (int) $companyId;
        $chatId = (int) $chatId;

        if (! userBelongsToCompany($user->id, $companyId)) {
            return false;
        }

        $perms = $user->getAllPermissionsForCompany($companyId)->pluck('name');
        if (! $user->is_admin && ! $perms->contains('chats_view_all') && ! $perms->contains('chats_view')) {
            return false;
        }

        ensureTenancyForBroadcastChannel($companyId);

        return DB::table('chat_participants')
            ->join('chats', 'chat_participants.chat_id', '=', 'chats.id')
            ->where('chats.company_id', $companyId)
            ->where('chat_participants.chat_id', $chatId)
            ->where('chat_participants.user_id', $user->id)
            ->exists();
    } catch (\Throwable $e) {
        report($e);

        return false;
    } finally {
        if (app()->has('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }
    }
});

Broadcast::channel('company.{companyId}.orders', function ($user, $companyId) {
    try {
        $companyId = (int) $companyId;
        if (! userBelongsToCompany($user->id, $companyId)) {
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
        if (! userBelongsToCompany($user->id, $companyId)) {
            return false;
        }
        // Виджет «Онлайн сейчас» — для всех сотрудников компании, не только с правами чатов
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


function userBelongsToCompany($userId, $companyId): bool
{
    $central = config('tenancy.database.central_connection', 'central');

    return DB::connection($central)
        ->table('company_user')
        ->where('company_id', $companyId)
        ->where('user_id', $userId)
        ->exists();
}
