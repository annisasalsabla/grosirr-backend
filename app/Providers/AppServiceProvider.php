<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Pagination\Paginator;
use App\Services\SerenityLoggerService;
use App\Services\TelegramBotService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SerenityLoggerService::class, function ($app) {
            return new SerenityLoggerService($app->make(TelegramBotService::class));
        });
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Paginator::useBootstrapFive();
        
        // Set locale ke Indonesia
        app()->setLocale('id');

        // Registrasi Transaction Observer
        \App\Models\Transaction::observe(\App\Observers\TransactionObserver::class);
        
        // Define Rate Limiters (Dipindahkan dari bootstrap/app.php agar kompatibel dengan route:cache)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
        
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
        
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
