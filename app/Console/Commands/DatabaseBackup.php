<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DatabaseBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature   = 'db:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export MySQL database to mysql_dumps folder';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host     = config('database.connections.mysql.host');
        $port     = config('database.connections.mysql.port', 3306);

        $dumpsPath = base_path('mysql_dumps');

        if (! is_dir($dumpsPath)) {
            mkdir($dumpsPath, 0755, true);
        }

        $filename = "{$database}_" . now()->format('Y-m-d_H-i-s') . '.sql.gz';
        $filepath = "{$dumpsPath}/{$filename}";

        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers %s | gzip > %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
            escapeshellarg($filepath),
        );

        $this->info("Backup начался: {$filename}");

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || ! file_exists($filepath) || filesize($filepath) === 0) {
            $this->error('Backup завершился с ошибкой!');
            Log::error('DB Backup failed', ['output' => $output, 'exit_code' => $exitCode]);
            return self::FAILURE;
        }

        $sizeMb = round(filesize($filepath) / 1024 / 1024, 2);
        $this->info("Backup готов: {$filename} ({$sizeMb} MB)");
        Log::info("DB Backup успешно: {$filename} ({$sizeMb} MB)");

        return self::SUCCESS;
    }
}
