<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    public function show(Request $request)
    {
        try {
            $user = $request->user();
            
            return $this->success([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at,
            ], 'Profil berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get profile error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat profil', null, 500);
        }
    }

    public function update(Request $request)
    {
        try {
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|string|max:15',
                'current_password' => 'required_if:new_password,exists|string',
                'new_password' => 'nullable|string|min:6|confirmed',
            ]);

            $user = $request->user();

            if ($request->has('name')) {
                $user->name = $request->name;
            }

            if ($request->has('phone')) {
                $user->phone = $request->phone;
            }

            if ($request->has('new_password') && $request->new_password) {
                if (!Hash::check($request->current_password, $user->password)) {
                    return $this->error('Password saat ini salah', null, 400);
                }
                $user->password = Hash::make($request->new_password);
            }

            $user->save();

            $this->logger->info('Profile updated by Cashier', [
                'user_id' => $user->id
            ]);

            return $this->success([
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ], 'Profil berhasil diperbarui', 200);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data profil tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Update profile error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memperbarui profil', null, 500);
        }
    }
}