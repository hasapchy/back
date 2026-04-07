<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function boot(): void
    {
        Route::post('/broadcasting/auth', function (\Illuminate\Http\Request $request) {
            return Broadcast::auth($request);
        })->middleware(['web', 'bc.json', 'auth:sanctum']);

        require base_path('routes/channels.php');
    }
}
