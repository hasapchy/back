<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // Основной rate limiter для API
        // 1000 запросов в минуту для авторизованных пользователей
        // 200 запросов в минуту для неавторизованных (по IP)
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(1000)->by($request->user()->id)
                : Limit::perMinute(200)->by($request->ip());
        });

        // Более строгий limiter для операций входа/регистрации
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Гибкий limiter для тяжелых операций (экспорт, отчеты)
        RateLimiter::for('heavy', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
