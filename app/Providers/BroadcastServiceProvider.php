<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class BroadcastServiceProvider extends ServiceProvider
{
     /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Broadcast::routes([
        //     'middleware' => ['auth:sanctum'],
        // ]);

        Route::post('/broadcasting/auth', function (\Illuminate\Http\Request $request) {
            return Broadcast::auth($request);
        })->middleware(['broadcast.json', 'auth:sanctum']);

        require base_path('routes/channels.php');
    }
}
