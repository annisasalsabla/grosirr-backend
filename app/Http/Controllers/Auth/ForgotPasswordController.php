<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use App\Services\EmailService;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ForgotPasswordController extends Controller
{
    use ApiResponseTrait;

    protected $emailService;
    protected $logger;

    public function __construct(EmailService $emailService, SerenityLoggerService $logger)
    {
        $this->emailService = $emailService;
        $this->logger = $logger;
    }

    public function sendResetLink(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email',
            ]);

            $user = User::where('email', $request->email)->first();
            
            if (!$user) {
                return $this->error('Email tidak terdaftar', null, 404);
            }

            $token = Str::random(60);
            Cache::put("reset_token_{$token}", $user->email, 3600); // Expire 1 jam

            $emailSent = $this->emailService->sendResetPassword($user->email, $token);

            if (!$emailSent) {
                return $this->error('Gagal mengirim email reset password. Silakan coba beberapa saat lagi.', null, 500);
            }

            $this->logger->info('Reset password link dikirim', [
                'email' => $request->email,
                'user_id' => $user->id
            ]);

            return $this->success(null, 'Link reset password telah dikirim ke email Anda', 200);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Email tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Send reset link error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengirim link reset password', null, 500);
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'token' => 'required|string',
                'password' => 'required|string|min:6|confirmed',
            ]);

            $email = Cache::get("reset_token_{$request->token}");
            
            if (!$email) {
                return $this->error('Token reset password tidak valid atau sudah kadaluarsa', null, 400);
            }

            $user = User::where('email', $email)->first();
            $user->password = bcrypt($request->password);
            $user->save();

            Cache::forget("reset_token_{$request->token}");

            $this->logger->info('Password berhasil direset', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return $this->success(null, 'Password berhasil direset. Silakan login dengan password baru Anda.', 200);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data reset password tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Reset password error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mereset password', null, 500);
        }
    }
}