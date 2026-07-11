<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Console\Commands\SendDailyReceivableNotification;
use App\Console\Commands\CheckLowStock;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\CheckRole;
use App\Services\SerenityLoggerService;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Define API rate limiter here
            RateLimiter::for('api', function (Request $request) {
                return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
            });
            
            // Define custom rate limiters
            RateLimiter::for('login', function (Request $request) {
                return Limit::perMinute(10)->by($request->ip());
            });
            
            RateLimiter::for('register', function (Request $request) {
                return Limit::perMinute(5)->by($request->ip());
            });
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'check.role' => CheckRole::class,
        ]);
        
        // Trust ngrok and other proxies so asset() generates https:// dynamic urls
        $middleware->trustProxies(at: '*');
        
        // Removed EnsureFrontendRequestsAreStateful - tidak perlu untuk API token-based
        
        $middleware->api([
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
        
        // Prevent redirect to login route for API requests
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Silakan login terlebih dahulu',
                    'code' => 401,
                ], 401);
            }
            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Exception handling
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $logger = app(SerenityLoggerService::class);
                
                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Silakan login terlebih dahulu',
                        'code' => 401,
                    ], 401);
                }
                
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $message = 'Validasi data gagal';
                    if (array_key_exists('start_date', $e->errors()) || array_key_exists('end_date', $e->errors())) {
                        $message = 'Tanggal mulai dan akhir wajib diisi untuk periode kustom';
                    }
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                        'errors' => $e->errors(),
                        'code' => 422,
                    ], 422);
                }
                
                if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $model = str_replace('App\\Models\\', '', $e->getModel());
                    return response()->json([
                        'success' => false,
                        'message' => "Data {$model} tidak ditemukan",
                        'code' => 404,
                    ], 404);
                }
                
                if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Terlalu banyak percobaan. Silakan coba lagi setelah beberapa saat.',
                        'code' => 429,
                    ], 429);
                }
                
                $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                
                if ($statusCode >= 500) {
                    $logger->error($e->getMessage(), [
                        'exception' => $e->getTraceAsString(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                        'ip' => $request->ip(),
                    ]);
                }
                
                $message = match ($statusCode) {
                    400 => 'Data yang dikirim tidak valid',
                    401 => 'Silakan login terlebih dahulu',
                    403 => 'Anda tidak memiliki izin',
                    404 => 'Data tidak ditemukan',
                    405 => 'Method tidak diizinkan',
                    422 => 'Validasi gagal',
                    429 => 'Terlalu banyak permintaan',
                    500 => 'Terjadi kesalahan pada server. Tim teknis telah diberitahu.',
                    default => 'Terjadi kesalahan, silakan coba lagi',
                };
                
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'code' => $statusCode,
                ], $statusCode);
            }
            
            return null;
        });
    })
    ->withCommands([
        SendDailyReceivableNotification::class,
        CheckLowStock::class,
    ])
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('receivable:send-daily-notification')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/schedule-receivable.log'));
        
        $schedule->command('stock:check-low')
            ->hourly()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/schedule-stock.log'));
    })
    ->create();