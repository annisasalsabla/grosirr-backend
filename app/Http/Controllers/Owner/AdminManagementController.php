<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use App\Services\EmailService;
use App\Services\SerenityLoggerService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AdminManagementController extends Controller
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

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            
            $admins = User::where('role', 'admin')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
            
            return $this->success($admins, 'Daftar admin berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get admin list error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar admin', null, 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'phone' => 'required|string|max:15|unique:users,phone',
                'password' => 'required|string|min:6',
            ]);
            
            $admin = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'role' => 'admin',
                'is_active' => true,
            ]);
            
            // Kirim notifikasi via WhatsApp
            $message = "Halo {$admin->name},\n\n";
            $message .= "Akun Admin Grosir Tiga Bersaudara telah dibuat untuk Anda.\n";
            $message .= "Email: {$admin->email}\n";
            $message .= "Password: {$request->password}\n\n";
            $message .= "Silakan login dan segera ubah password Anda.\n";
            $message .= "Terima kasih.";
            
            $this->whatsappService->sendMessage($admin->phone, $message);
            
            // Kirim email notifikasi
            $this->emailService->sendOtp($admin->email, 'AKUN_ADMIN');
            
            // Hapus cache dashboard
            Cache::forget('owner_dashboard_' . date('Y-m-d'));
            
            $this->logger->info('Admin baru berhasil dibuat oleh Owner', [
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'owner_id' => $request->user()->id
            ]);
            
            return $this->success($admin, 'Akun admin berhasil dibuat. Notifikasi telah dikirim via WhatsApp dan Email.', 201);
            
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data admin tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Create admin error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat membuat akun admin', null, 500);
        }
    }

    public function toggleActive($id, Request $request)
    {
        try {
            $admin = User::where('role', 'admin')->findOrFail($id);
            
            $admin->is_active = !$admin->is_active;
            $admin->save();
            
            $status = $admin->is_active ? 'diaktifkan' : 'dinonaktifkan';
            
            // Kirim notifikasi WhatsApp
            $message = "Halo {$admin->name},\n\n";
            $message .= "Akun Admin Anda telah {$status} oleh Owner.\n";
            $message .= "Jika ada pertanyaan, silakan hubungi Owner.";
            
            $this->whatsappService->sendMessage($admin->phone, $message);
            
            Cache::forget('owner_dashboard_' . date('Y-m-d'));
            
            $this->logger->info("Admin {$status} oleh Owner", [
                'admin_id' => $admin->id,
                'status' => $admin->is_active ? 'active' : 'inactive'
            ]);
            
            return $this->success([
                'is_active' => $admin->is_active
            ], "Akun admin berhasil {$status}", 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Toggle admin status error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengubah status admin', null, 500);
        }
    }

    public function destroy($id)
    {
        try {
            $admin = User::where('role', 'admin')->findOrFail($id);
            $admin->delete();
            
            Cache::forget('owner_dashboard_' . date('Y-m-d'));
            
            $this->logger->info('Admin dihapus oleh Owner', [
                'admin_id' => $id,
                'admin_email' => $admin->email
            ]);
            
            return $this->success(null, 'Akun admin berhasil dihapus', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Delete admin error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menghapus akun admin', null, 500);
        }
    }
}