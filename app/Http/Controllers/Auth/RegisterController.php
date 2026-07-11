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

class RegisterController extends Controller
{
    use ApiResponseTrait;

    protected $emailService;
    protected $logger;

    public function __construct(EmailService $emailService, SerenityLoggerService $logger)
    {
        $this->emailService = $emailService;
        $this->logger = $logger;
    }

    public function register(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'phone' => 'required|string|max:15',
                'password' => 'required|string|min:6|confirmed',
                'role' => 'sometimes|in:owner,admin,cashier',
            ]);

            // Hanya admin yang bisa membuat akun cashier, owner hanya bisa membuat admin
            $role = $request->role ?? 'cashier';
            
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'role' => $role,
                'is_active' => true,
            ]);

            // Generate OTP code
            $otpCode = rand(100000, 999999);
            Cache::put("otp_{$user->email}", $otpCode, 600); // Expire 10 menit

            // Kirim OTP ke email
            $this->emailService->sendOtp($user->email, $otpCode);

            $this->logger->info('User berhasil registrasi', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $role
            ]);

            return $this->success([
                'user_id' => $user->id,
                'email' => $user->email,
                'message' => 'Kode OTP telah dikirim ke email Anda. Silakan verifikasi.',
            ], 'Registrasi berhasil. Silakan cek email untuk kode verifikasi.', 201);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data registrasi tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Registrasi error: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
            return $this->error('Terjadi kesalahan saat registrasi', null, 500);
        }
    }

    public function verifyOtp(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'otp_code' => 'required|string|size:6',
            ]);

            $cachedOtp = Cache::get("otp_{$request->email}");
            
            if (!$cachedOtp || $cachedOtp != $request->otp_code) {
                return $this->error('Kode OTP tidak valid atau sudah kadaluarsa', null, 400);
            }

            $user = User::where('email', $request->email)->first();
            $user->email_verified_at = now();
            $user->save();

            Cache::forget("otp_{$request->email}");

            $token = $user->createToken('auth_token_' . $user->role)->plainTextToken;

            $this->logger->info('User verifikasi OTP berhasil', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return $this->success([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'token' => $token,
            ], 'Verifikasi berhasil. Silakan login.', 200);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data verifikasi tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Verifikasi OTP error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat verifikasi', null, 500);
        }
    }
}