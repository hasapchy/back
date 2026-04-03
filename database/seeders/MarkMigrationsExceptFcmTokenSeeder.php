<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Помечает все файлы миграций как выполненные в таблице migrations,
 * кроме create_user_fcm_token — её можно применить отдельно: php artisan migrate.
 */
class MarkMigrationsExceptFcmTokenSeeder extends Seeder
{
    private const EXCLUDED_MIGRATION = '2026_04_03_120000_create_user_fcm_token_table.php';

    public function run(): void
    {
        DB::table('migrations')->where('migration', self::EXCLUDED_MIGRATION)->delete();

        $files = collect(File::files(database_path('migrations')))
            ->map(fn (\SplFileInfo $file) => $file->getFilename())
            ->filter(fn (string $name) => str_ends_with($name, '.php'))
            ->reject(fn (string $name) => $name === self::EXCLUDED_MIGRATION)
            ->sort()
            ->values();

        $nextBatch = (int) (DB::table('migrations')->max('batch') ?? 0) + 1;

        foreach ($files as $migration) {
            DB::table('migrations')->insertOrIgnore([
                'migration' => $migration,
                'batch' => $nextBatch,
            ]);
        }
    }
}
