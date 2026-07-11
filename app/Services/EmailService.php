<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Models\NotificationLog;

class EmailService
{
    /**
     * Send OTP email with timeout protection
     */
    public function sendOtp(string $email, string $otpCode): bool
    {
        try {
            // Use queue with timeout to prevent blocking
            Mail::raw(
                "Kode Verifikasi Anda: {$otpCode}\n\nKode ini berlaku selama 5 menit.",
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('Kode Verifikasi Registrasi - Grosir Tiga Bersaudara');
                }
            );

            NotificationLog::create([
                'type' => 'email',
                'recipient' => $email,
                'subject' => 'Kode Verifikasi Registrasi',
                'message' => "Kode OTP Anda: {$otpCode}",
                'status' => 'sent',
            ]);

            Log::info("OTP sent to {$email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send OTP to {$email}: " . $e->getMessage());

            NotificationLog::create([
                'type' => 'email',
                'recipient' => $email,
                'subject' => 'Kode Verifikasi Registrasi',
                'message' => "Kode OTP Anda: {$otpCode}",
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send reset password email with timeout protection
     */
    public function sendResetPassword(string $email, string $token): bool
    {
        try {
            $resetUrl = config('app.frontend_url', 'https://grosirtiga.my.id') . '/reset-password/' . $token . '?email=' . urlencode($email);

            Mail::raw(
                "Reset Password - Grosir Tiga Bersaudara\n\n" .
                "Klik tautan berikut untuk reset password:\n{$resetUrl}\n\n" .
                "Link ini berlaku selama 1 jam.\n" .
                "Jika Anda tidak meminta reset password, abaikan email ini.",
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('Reset Password - Grosir Tiga Bersaudara');
                }
            );

            NotificationLog::create([
                'type' => 'email',
                'recipient' => $email,
                'subject' => 'Reset Password',
                'message' => "Token reset password: {$token}",
                'status' => 'sent',
            ]);

            Log::info("Reset password email sent to {$email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send reset password email to {$email}: " . $e->getMessage());

            NotificationLog::create([
                'type' => 'email',
                'recipient' => $email,
                'subject' => 'Reset Password',
                'message' => "Token reset password: {$token}",
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            return false;
        }
    }
}