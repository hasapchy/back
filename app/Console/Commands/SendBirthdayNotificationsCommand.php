<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\User;
use App\Services\InAppNotifications\InAppNotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendBirthdayNotificationsCommand extends Command
{
    protected $signature = 'notifications:send-birthdays';

    protected $description = 'Send in-app birthday notifications to company members';

    /**
     * Execute the console command.
     */
    public function handle(InAppNotificationDispatcher $dispatcher): int
    {
        $today = Carbon::today('Asia/Ashgabat');
        $birthdayUsers = User::query()
            ->where('is_active', true)
            ->whereNotNull('birthday')
            ->whereMonth('birthday', $today->month)
            ->whereDay('birthday', $today->day)
            ->whereHas('companies')
            ->with('companies:id')
            ->get();

        foreach ($birthdayUsers as $birthdayUser) {
            $companyIds = $birthdayUser->companies->modelKeys();

            $fullName = trim((string) $birthdayUser->name.' '.(string) $birthdayUser->surname);

            foreach ($companyIds as $companyId) {
                if (AppNotification::query()
                    ->where('company_id', $companyId)
                    ->where('channel_key', 'birthdays_today')
                    ->whereDate('created_at', $today->toDateString())
                    ->where('data->birthday_user_id', (int) $birthdayUser->id)
                    ->exists()
                ) {
                    continue;
                }

                $dispatcher->dispatch(
                    $companyId,
                    'birthdays_today',
                    (int) $birthdayUser->id,
                    '',
                    null,
                    [
                        'route' => '/users',
                        'birthday_user_id' => (int) $birthdayUser->id,
                        'birthday_user_name' => $fullName,
                    ]
                );
            }
        }

        return self::SUCCESS;
    }
}
