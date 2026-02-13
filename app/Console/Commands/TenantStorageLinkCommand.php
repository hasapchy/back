<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Создаёт симлинки public/storage/tenant/{company_id} → storage/tenant{id}/app/public
 * для доступа к файлам тенанта (чаты, задачи и т.д.) по URL /storage/tenant/{company_id}/...
 * Аналог php artisan storage:link для tenant-хранилищ.
 */
class TenantStorageLinkCommand extends Command
{
    protected $signature = 'tenant:storage-link
                            {--force : Перезаписать существующие ссылки}';

    protected $description = 'Создать симлинки для публичного доступа к файлам тенантов (чаты, задачи)';

    public function handle(Filesystem $files): int
    {
        $suffixBase = config('tenancy.filesystem.suffix_base', 'tenant');
        $storageRoot = base_path('storage');
        $tenantDir = public_path('storage/tenant');

        if (!$files->isDirectory($tenantDir)) {
            $files->makeDirectory($tenantDir, 0755, true);
        }

        $companies = Company::whereNotNull('tenant_id')->get();
        if ($companies->isEmpty()) {
            $this->warn('Нет компаний с tenant_id. Ссылки не созданы.');
            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($companies as $company) {
            $targetPath = $storageRoot . '/' . $suffixBase . $company->tenant_id . '/app/public';
            $linkPath = $tenantDir . '/' . $company->id;

            if (!is_dir($targetPath)) {
                $this->line("  [пропуск] компания {$company->id}: папка не найдена: {$targetPath}");
                $skipped++;
                continue;
            }

            if (file_exists($linkPath)) {
                if (!$this->option('force')) {
                    $this->line("  [пропуск] компания {$company->id}: ссылка уже есть: {$linkPath}");
                    $skipped++;
                    continue;
                }
                $files->delete($linkPath);
            }

            try {
                $files->link($targetPath, $linkPath);
                $this->line("  [ok] компания {$company->id}: {$linkPath} → {$targetPath}");
                $created++;
            } catch (\Throwable $e) {
                $this->error("  [ошибка] компания {$company->id}: " . $e->getMessage());
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Готово: создано {$created}, пропущено {$skipped}, ошибок {$errors}.");
        $this->comment('Файлы доступны по URL: ' . url('storage/tenant/{company_id}/chats/...'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
