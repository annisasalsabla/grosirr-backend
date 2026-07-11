<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use App\Services\TelegramBotService;
use Sentry\SentrySdk;

class Handler extends ExceptionHandler
{
    protected $levels = [];

    protected $dontReport = [];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            $this->reportToSentry($e);
            $this->reportToTelegram($e);
        });

        $this->renderable(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/*')) {
                $message = 'Data yang dikirim tidak valid';
                if (array_key_exists('start_date', $e->errors()) || array_key_exists('end_date', $e->errors())) {
                    $message = 'Tanggal mulai dan akhir wajib diisi untuk periode kustom';
                }
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => $e->errors()
                ], 422);
            }
        });
    }

    protected function reportToSentry(Throwable $e): void
    {
        try {
            if (class_exists('Sentry\SentrySdk')) {
                \Sentry\SentrySdk::getCurrentHub()->captureException($e);
            }
        } catch (\Exception $sentryError) {
            \Log::error('Sentry capture failed: ' . $sentryError->getMessage());
        }
    }

    protected function reportToTelegram(Throwable $e): void
    {
        // DEBUG: Log awal fungsi
        \Log::info('=== reportToTelegram DI PANGGIL ===');

        // Cek apakah notification diaktifkan di .env
        if (!config('telegram.error_notification.enabled', false)) {
            \Log::info('reportToTelegram skipping - disabled in config');
            return;
        }

        // Debug logging
        \Log::error($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        try {
            $telegram = app(TelegramBotService::class);

            $url = request() ? request()->fullUrl() : 'N/A';
            $method = request() ? request()->method() : 'N/A';

            $message = "<b>ERROR 500</b>\n\n";
            $message .= "Error: " . $e->getMessage() . "\n\n";
            $message .= "File: " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
            $message .= "URL: " . $url . "\n";
            $message .= "Method: " . $method;

            $telegram->sendMessage($message);
        } catch (\Exception $telegramError) {
            \Log::error('Telegram notification failed: ' . $telegramError->getMessage());
        }
    }
}