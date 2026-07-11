<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ChangePasswordController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6|confirmed',
            ]);

            $user = $request->user();

            if (!Hash::check($request->current_password, $user->password)) {
                return $this->error('Password saat ini salah', null, 400);
            }

            $user->password = Hash::make($request->new_password);
            $user->save();

            $this->logger->info('Password berhasil diubah', [
                'user_id' => $user->id,
                'role' => $user->role
            ]);

            return $this->success(null, 'Password berhasil diubah', 200);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data password tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Change password error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengubah password', null, 500);
        }
    }
}