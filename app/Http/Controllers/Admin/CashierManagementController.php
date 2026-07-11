<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use App\Services\EmailService;
use App\Services\SerenityLoggerService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class CashierManagementController extends Controller
{
    use ApiResponseTrait;

    protected $emailService;
    protected $whatsappService;
    protected $logger;

    public function __construct(
        EmailService $emailService,
        WhatsAppService $whatsappService,
        SerenityLoggerService $logger
    ) {
        $this->emailService = $emailService;
        $this->whatsappService = $whatsappService;
        $this->logger = $logger;
    }

    /**
     * Display a listing of the resource.
     * GET /api/admin/cashiers
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            
            $cashiers = User::where('role', 'cashier')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
            
            return $this->success($cashiers, 'Daftar kasir berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get cashier list error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar kasir', null, 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     * POST /api/admin/cashiers
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'phone' => 'required|string|max:15|unique:users,phone',
                'password' => 'required|string|min:6',
            ]);
            
            $cashier = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'role' => 'cashier',
                'is_active' => true,
            ]);
            
            // Kirim notifikasi via WhatsApp
            $message = "Halo {$cashier->name},\n\n";
            $message .= "Akun Kasir Grosir Tiga Bersaudara telah dibuat untuk Anda.\n";
            $message .= "Email: {$cashier->email}\n";
            $message .= "Password: {$request->password}\n\n";
            $message .= "Silakan login dan segera ubah password Anda.\n";
            $message .= "Terima kasih.";
            
            $this->whatsappService->sendMessage($cashier->phone, $message);
            
            // Kirim email notifikasi
            $this->emailService->sendOtp($cashier->email, 'AKUN_KASIR');
            
            $this->logger->info('Kasir baru berhasil dibuat oleh Admin', [
                'cashier_id' => $cashier->id,
                'cashier_email' => $cashier->email,
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success($cashier, 'Akun kasir berhasil dibuat. Notifikasi telah dikirim via WhatsApp.', 201);
            
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data kasir tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Create cashier error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat membuat akun kasir', null, 500);
        }
    }

    /**
     * Update the specified resource in storage.
     * PUT /api/admin/cashiers/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $cashier = User::where('role', 'cashier')->findOrFail($id);
            
            $rules = [];
            
            if ($request->has('name')) {
                $rules['name'] = 'sometimes|string|max:255';
            }
            
            if ($request->has('email')) {
                $rules['email'] = [
                    'sometimes',
                    'email',
                    Rule::unique('users', 'email')->ignore($cashier->id)
                ];
            }
            
            if ($request->has('phone')) {
                $rules['phone'] = [
                    'sometimes',
                    'string',
                    'max:15',
                    Rule::unique('users', 'phone')->ignore($cashier->id)
                ];
            }
            
            if ($request->has('password')) {
                $rules['password'] = 'sometimes|string|min:6';
                $rules['password_confirmation'] = 'required_with:password|same:password';
            }
            
            $request->validate($rules);
            
            // Update fields
            if ($request->has('name')) {
                $cashier->name = $request->name;
            }
            
            if ($request->has('email')) {
                $cashier->email = $request->email;
            }
            
            if ($request->has('phone')) {
                $cashier->phone = $request->phone;
            }
            
            if ($request->has('password')) {
                $cashier->password = Hash::make($request->password);
            }
            
            $cashier->save();
            
            $this->logger->info('Kasir berhasil diupdate oleh Admin', [
                'cashier_id' => $cashier->id,
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success($cashier, 'Akun kasir berhasil diperbarui', 200);
            
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data kasir tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Update cashier error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memperbarui akun kasir', null, 500);
        }
    }

    /**
     * Toggle active status of cashier.
     * PATCH /api/admin/cashiers/{id}/toggle-active
     */
    public function toggleActive($id, Request $request)
    {
        try {
            $cashier = User::where('role', 'cashier')->findOrFail($id);
            
            $cashier->is_active = !$cashier->is_active;
            $cashier->save();
            
            $status = $cashier->is_active ? 'diaktifkan' : 'dinonaktifkan';
            
            // Kirim notifikasi WhatsApp
            $message = "Halo {$cashier->name},\n\n";
            $message .= "Akun Kasir Anda telah {$status} oleh Admin.\n";
            $message .= "Jika ada pertanyaan, silakan hubungi Admin.";
            
            $this->whatsappService->sendMessage($cashier->phone, $message);
            
            $this->logger->info("Kasir {$status} oleh Admin", [
                'cashier_id' => $cashier->id,
                'status' => $cashier->is_active ? 'active' : 'inactive'
            ]);
            
            return $this->success([
                'is_active' => $cashier->is_active
            ], "Akun kasir berhasil {$status}", 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Toggle cashier status error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengubah status kasir', null, 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     * DELETE /api/admin/cashiers/{id}
     */
    public function destroy($id)
    {
        try {
            $cashier = User::where('role', 'cashier')->findOrFail($id);
            $cashier->delete();
            
            $this->logger->info('Kasir dihapus oleh Admin', [
                'cashier_id' => $id,
                'cashier_email' => $cashier->email
            ]);
            
            return $this->success(null, 'Akun kasir berhasil dihapus', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Delete cashier error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menghapus akun kasir', null, 500);
        }
    }
}