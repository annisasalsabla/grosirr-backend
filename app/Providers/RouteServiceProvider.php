<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Tidak perlu melakukan apa-apa karena Laravel 13 menggunakan bootstrap/app.php
    }
}
