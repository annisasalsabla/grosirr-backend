<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use App\Services\EmailService;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ResetPasswordController extends Controller
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

            $token = \Illuminate\Support\Str::random(60);
            Cache::put("reset_token_{$token}", $user->email, 3600);

            $this->emailService->sendResetPassword($user->email, $token);

            $this->logger->info('Reset password link sent', [
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
            if (!$user) {
                return $this->error('Pengguna tidak ditemukan', null, 404);
            }

            $user->password = Hash::make($request->password);
            $user->save();

            Cache::forget("reset_token_{$request->token}");

            $this->logger->info('Password reset successful', [
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

    // Web routes - for browser access
    public function showResetForm($token)
    {
        $email = Cache::get("reset_token_{$token}");

        if (!$email) {
            return '<h1>Link Reset Password Expired atau Tidak Valid</h1><p>Silakan minta link reset password lagi.</p>';
        }

        return '<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 400px; margin: 0 auto; }
        input { width: 100%; padding: 12px; margin: 8px 0; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #2563eb; color: white; border: none; cursor: pointer; }
        .message { padding: 10px; margin: 10px 0; }
        .success { background: #d1fae5; color: #065f46; }
        .error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <h1>Reset Password</h1>
    <form id="resetForm">
        <input type="hidden" name="token" value="'.$token.'">
        <input type="hidden" name="email" value="'.$email.'">
        <label>Password Baru:</label>
        <input type="password" name="password" required minlength="6" placeholder="Min 6 karakter">
        <label>Konfirmasi Password:</label>
        <input type="password" name="password_confirmation" required minlength="6" placeholder="Min 6 karakter">
        <button type="submit">Reset Password</button>
    </form>
    <div id="message"></div>
    <script>
        document.getElementById("resetForm").addEventListener("submit", async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData);
            data.password_confirmation = data.password_confirmation;

            try {
                const res = await fetch(window.location.origin + "/api/reset-password", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(data)
                });
                const result = await res.json();
                const msg = document.getElementById("message");
                if (res.ok) {
                    msg.innerHTML = "<div class=\'message success\'>" + result.message + "</div>";
                    document.getElementById("resetForm").style.display = "none";
                } else {
                    msg.innerHTML = "<div class=\'message error\'>" + (result.message || "Error") + "</div>";
                }
            } catch (err) {
                document.getElementById("message").innerHTML = "<div class=\'message error\'>Network error</div>";
            }
        });
    </script>
</body>
</html>';
    }
}