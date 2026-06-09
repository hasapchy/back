<?php

namespace App\Console\Commands;

use App\Services\CacheKeyRegistry;
use Illuminate\Console\Command;

class PruneCacheKeyRegistryCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cache:prune-key-registry';

    /**
     * @var string
     */
    protected $description = 'Remove expired cache keys from the file cache key registry';

    /**
     * @return int
     */
    public function handle(): int
    {
        if (config('cache.default') !== 'file') {
            $this->components->info('Cache key registry prune skipped: cache driver is not file.');

            return self::SUCCESS;
        }

        $removed = CacheKeyRegistry::prune();
        $this->components->info("Removed {$removed} stale entries from cache key registry.");

        return self::SUCCESS;
    }
}
