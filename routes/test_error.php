<?php

use Illuminate\Support\Facades\Route;

Route::get('/test-error-handler', function () {
    throw new \Exception('Test error triggered at ' . date('Y-m-d H:i:s'));
});