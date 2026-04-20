<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    protected $commands = [
        Commands\DatabaseBackup::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('templates:publish-all')
            ->dailyAt('00:00')
            ->timezone('Asia/Ashgabat');

        $schedule->command('recurring-transactions:run')
            ->dailyAt('00:05')
            ->timezone('Asia/Ashgabat');

        $schedule->command('notifications:send-birthdays')
            ->dailyAt('00:10')
            ->timezone('Asia/Ashgabat');


            // Бэкап в 08:00 (UTC+5)
        $schedule
        ->command('db:backup')
        ->dailyAt('03:00')
        ->timezone('Asia/Tashkent')
        ->withoutOverlapping()
        ->runInBackground();

        // Бэкап в 23:00 (UTC+5)
        $schedule
            ->command('db:backup')
            ->dailyAt('18:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
