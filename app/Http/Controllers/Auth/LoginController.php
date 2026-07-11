<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                $this->logger->warning('Login gagal - email atau password salah', [
                    'email' => $request->email,
                    'ip' => $request->ip()
                ]);
                
                return $this->error('Email atau password salah', null, 401);
            }

            if (!$user->is_active) {
                return $this->error('Akun Anda telah dinonaktifkan. Silakan hubungi admin.', null, 403);
            }

            $token = $user->createToken('auth_token_' . $user->role)->plainTextToken;

            // Cache user data untuk performance
            Cache::put("user_{$user->id}", $user, 3600);

            $this->logger->info('User login berhasil', [
                'user_id' => $user->id,
                'role' => $user->role,
                'email' => $user->email
            ]);

            return $this->success([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ], 'Login berhasil', 200);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data yang dikirim tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Login error: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString(),
                'email' => $request->email
            ]);
            
            return $this->error('Terjadi kesalahan saat login. Silakan coba lagi.', null, 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            $user->currentAccessToken()->delete();

            Cache::forget("user_{$user->id}");

            $this->logger->info('User logout berhasil', [
                'user_id' => $user->id,
                'role' => $user->role
            ]);

            return $this->success(null, 'Logout berhasil', 200);
        } catch (\Exception $e) {
            $this->logger->error('Logout error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat logout', null, 500);
        }
    }

    /**
     * Get current authenticated user (for Flutter check on app start/restart)
     * GET /api/me
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user || !$user->is_active) {
                return $this->error('Session expired. Silakan login ulang.', null, 401);
            }

            return $this->success([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                ],
                'is_authenticated' => true,
            ], 'User session valid', 200);
        } catch (\Exception $e) {
            return $this->error('Session expired. Silakan login ulang.', null, 401);
        }
    }
}