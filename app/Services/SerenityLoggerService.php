<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Sentry\Laravel\Facade as Sentry;

class SerenityLoggerService
{
    protected $telegramService;
    protected $isEnabled;
    protected $sentryEnabled;

    public function __construct(TelegramBotService $telegramService)
    {
        $this->telegramService = $telegramService;
        $this->isEnabled = env('SERENITY_ENABLED', true);
        
        // Sentry hanya aktif di staging/production
        $this->sentryEnabled = in_array(env('APP_ENV', 'local'), ['staging', 'production']) 
            && env('SENTRY_ENABLED', true);
    }

    public function log(string $level, string $message, array $context = [])
    {
        // Log ke Laravel log
        Log::channel('daily')->log($level, $message, $context);

        if (!$this->isEnabled) {
            return;
        }

/** @var \Illuminate\Http\Request $req */
$req    = request();
$ip     = (string) $req->ip();
$url    = (string) $req->fullUrl();
$method = (string) $req->method();

$logEntry = [
    'timestamp' => now()->toIso8601String(),
    'level'     => $level,
    'message'   => $message,
    'context'   => $context,
    'ip'        => $ip,
    'url'       => $url,
    'method'    => $method,
    'user_id'   => auth()->id(),
    'user_role' => auth()->user()->role ?? null,
    'app_env'   => env('APP_ENV', 'local'),
];

        // Simpan ke file serenity.log
        $this->saveToFile($logEntry);

        // Kirim ke Sentry jika error/critical dan environment staging/production
        if (in_array($level, ['error', 'critical', 'emergency']) && $this->sentryEnabled) {
            $this->sendToSentry($message, $context);
        }

        // Kirim ke Telegram jika error/critical (semua environment)
        if (in_array($level, ['error', 'critical', 'emergency'])) {
            $this->sendToTelegram($logEntry);
        }

        return $logEntry;
    }

    protected function saveToFile(array $logEntry)
    {
        $logFile = storage_path('logs/serenity.json');
        $existing = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
        if (!is_array($existing)) {
            $existing = [];
        }
        array_unshift($existing, $logEntry);
        $existing = array_slice($existing, 0, 1000);
        
        file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));
    }

    protected function sendToSentry(string $message, array $context = []): void
{
    try {
        $hint = \Sentry\EventHint::fromArray([
            'extra' => $context,
        ]);
        Sentry::captureMessage($message, \Sentry\Severity::error(), $hint);
    } catch (\Exception $e) {
        // Silent fail
    }
}

    protected function sendToTelegram(array $logEntry)
    {
        $emoji = $logEntry['app_env'] === 'production' ? '🔴' : '⚠️';
        $envText = $logEntry['app_env'] === 'production' ? 'PRODUCTION' : strtoupper($logEntry['app_env']);
        
        $message = "{$emoji} *ERROR SYSTEM - {$envText}* {$emoji}\n\n";
        $message .= "🕐 Waktu: {$logEntry['timestamp']}\n";
        $message .= "⚠️ Level: {$logEntry['level']}\n";
        $message .= "📝 Pesan: {$logEntry['message']}\n";
        $message .= "📍 URL: {$logEntry['url']}\n";
        $message .= "🔧 Method: {$logEntry['method']}\n";
        $message .= "👤 User ID: {$logEntry['user_id']}\n";
        $message .= "🎭 Role: {$logEntry['user_role']}\n";
        $message .= "🌐 IP: {$logEntry['ip']}\n";

        if (!empty($logEntry['context']['exception'])) {
            $exceptionTrace = substr($logEntry['context']['exception'], 0, 500);
            $message .= "\n💥 Exception: {$exceptionTrace}";
        }

        if (!empty($logEntry['context']['file'])) {
            $message .= "\n📁 File: {$logEntry['context']['file']}";
        }
        if (!empty($logEntry['context']['line'])) {
            $message .= "\n📍 Line: {$logEntry['context']['line']}";
        }

        $this->telegramService->sendMessage($message);
    }

    public function info(string $message, array $context = [])
    {
        return $this->log('info', $message, $context);
    }

    public function error(string $message, array $context = [])
    {
        return $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = [])
    {
        return $this->log('warning', $message, $context);
    }

    public function critical(string $message, array $context = [])
    {
        return $this->log('critical', $message, $context);
    }

    public function emergency(string $message, array $context = [])
    {
        return $this->log('emergency', $message, $context);
    }
}