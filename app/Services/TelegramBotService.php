<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
class TelegramBotService
{
    protected $botToken;
    protected $chatId;
    protected $isEnabled;

    public function __construct()
    {
        $this->botToken = env('TELEGRAM_BOT_TOKEN');
        $this->chatId = env('TELEGRAM_CHAT_ID');
        
        // Telegram notifikasi error hanya aktif di staging/production
        $this->isEnabled = in_array(env('APP_ENV', 'local'), ['staging', 'production']) 
            && $this->botToken && $this->chatId;
    }

    public function sendMessage(string $message): bool
    {
        if (!$this->isEnabled) {
            // Di development, log saja ke file
            \Illuminate\Support\Facades\Log::info('Telegram not sent (development mode): ' . $message);
            return false;
        }

        if (!$this->botToken || !$this->chatId) {
            return false;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            $success = $response->successful();

            if ($success) {
                \Illuminate\Support\Facades\Log::info('Telegram notification sent successfully');
            } else {
                \Illuminate\Support\Facades\Log::error('Telegram notification failed: ' . $response->body());
            }

            return $success;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Telegram send error: ' . $e->getMessage());
            return false;
        }
    }

    public function sendTestMessage(): bool
    {
        $message = "✅ *TEST CONNECTION*\n\n";
        $message .= "Bot Telegram Grosir Tiga Bersaudara berhasil terhubung!\n";
        $message .= "Environment: " . env('APP_ENV', 'local') . "\n";
        $message .= "Waktu: " . now()->format('d/m/Y H:i:s');
        
        return $this->sendMessage($message);
    }
}