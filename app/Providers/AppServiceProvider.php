<?php

namespace App\Providers;

use App\Batch\BatchOperationRegistrar;
use App\Batch\BatchOperationRegistry;
use App\Models\Sanctum\PersonalAccessToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
    }
}
