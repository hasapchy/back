<?php

namespace App\Providers;

use App\Batch\BatchOperationRegistrar;
use App\Batch\BatchOperationRegistry;
use App\Models\Sanctum\PersonalAccessToken;
use App\Models\WarehouseStock;
use App\Observers\ActivityLogObserver;
use App\Observers\WarehouseStockObserver;
use App\Services\CacheKeyRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BatchOperationRegistry::class, function ($app) {
            $registry = new BatchOperationRegistry;
            BatchOperationRegistrar::register($registry, $app);

            return $registry;
        });
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        WarehouseStock::observe(WarehouseStockObserver::class);

        $activityModel = config('activitylog.activity_model', Activity::class);
        if (is_string($activityModel) && class_exists($activityModel)) {
            $activityModel::observe(ActivityLogObserver::class);
        }

        Event::listen('cache:cleared', function (): void {
            if (config('cache.default') === 'file') {
                CacheKeyRegistry::clear();
            }
        });
    }
}
