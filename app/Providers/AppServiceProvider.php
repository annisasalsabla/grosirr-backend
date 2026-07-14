<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Pagination\Paginator;
use App\Services\SerenityLoggerService;
use App\Services\TelegramBotService;

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
    }
}
