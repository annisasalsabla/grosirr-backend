<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            
            $suppliers = Supplier::orderBy('name')->paginate($perPage);
            
            return $this->success($suppliers, 'Daftar supplier berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get suppliers error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar supplier', null, 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'address' => 'required|string',
                'phone' => 'required|string|max:15',
                'product_type' => 'required|in:egg,rice',
                'username' => [
                    'nullable',
                    'string',
                    'unique:users,username',
                    'unique:users,email'
                ],
                'password' => 'required_with:username|nullable|string|min:6',
            ]);
            
            DB::beginTransaction();
            try {
                $userId = null;
                if ($request->filled('username')) {
                    $user = User::create([
                        'name' => $request->name,
                        'username' => $request->username,
                        'password' => Hash::make($request->password),
                        'role' => 'supplier',
                        'is_active' => true,
                    ]);
                    $userId = $user->id;
                }

                $supplier = Supplier::create(array_merge(
                    $request->only(['name', 'address', 'phone', 'product_type']),
                    ['user_id' => $userId]
                ));
                
                DB::commit();

                $this->logger->info('Supplier created by Admin', [
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'admin_id' => $request->user()->id
                ]);
                
                return $this->success($supplier, 'Supplier berhasil ditambahkan', 201);
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->logger->error('Create supplier error: ' . $e->getMessage());
                return $this->error('Terjadi kesalahan sistem, data gagal disimpan', null, 500);
            }
            
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data supplier tidak valid');
        }
    }

    public function show($id)
    {
        try {
            $supplier = Supplier::findOrFail($id);
            return $this->success($supplier, 'Detail supplier berhasil dimuat', 200);
        } catch (\Exception $e) {
            return $this->error('Supplier tidak ditemukan', null, 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $supplier = Supplier::with('user')->findOrFail($id);
            $userId = $supplier->user_id;

            $request->validate([
                'name' => 'sometimes|string|max:255',
                'address' => 'sometimes|string',
                'phone' => 'sometimes|string|max:15',
                'product_type' => 'sometimes|in:egg,rice',
                'username' => [
                    'nullable',
                    'string',
                    $userId ? \Illuminate\Validation\Rule::unique('users', 'username')->ignore($userId) : 'unique:users,username',
                    $userId ? \Illuminate\Validation\Rule::unique('users', 'email')->ignore($userId) : 'unique:users,email'
                ],
                'password' => [
                    $userId ? 'nullable' : 'required_with:username',
                    'string',
                    'min:6'
                ]
            ]);

            DB::beginTransaction();
            try {
                $supplier->update($request->only(['name', 'address', 'phone', 'product_type']));

                if ($request->filled('username') || $request->filled('password')) {
                    if ($userId) {
                        $user = $supplier->user;
                        if ($request->filled('username')) {
                            $user->username = $request->username;
                        }
                        if ($request->has('name')) {
                            $user->name = $request->name;
                        }
                        if ($request->filled('password')) {
                            $user->password = Hash::make($request->password);
                        }
                        $user->save();
                    } else {
                        // Jika belum punya akun, wajib isi password dari frontend saat kirim username (ditangkap oleh rule validasi)
                        if ($request->filled('username')) {
                            $user = User::create([
                                'name' => $request->name ?? $supplier->name,
                                'username' => $request->username,
                                'password' => Hash::make($request->password),
                                'role' => 'supplier',
                                'is_active' => true,
                            ]);
                            $supplier->user_id = $user->id;
                            $supplier->save();
                        }
                    }
                }

                DB::commit();

                $this->logger->info('Supplier updated by Admin', [
                    'supplier_id' => $supplier->id,
                    'admin_id' => $request->user()->id
                ]);

                return $this->success($supplier, 'Supplier berhasil diperbarui', 200);

            } catch (\Exception $e) {
                DB::rollBack();
                $this->logger->error('Transaction error in Supplier Update: ' . $e->getMessage());
                return $this->error('Terjadi kesalahan saat memproses data akun/profil', null, 500);
            }

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data supplier tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Update supplier error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memperbarui supplier', null, 500);
        }
    }

    public function destroy($id, Request $request)
    {
        try {
            $supplier = Supplier::findOrFail($id);
            
            // Cek apakah supplier memiliki produk
            if ($supplier->products()->exists()) {
                return $this->error('Supplier tidak dapat dihapus karena masih memiliki produk', null, 400);
            }
            
            $supplier->delete();
            
            $this->logger->info('Supplier deleted by Admin', [
                'supplier_id' => $id,
                'supplier_name' => $supplier->name,
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success(null, 'Supplier berhasil dihapus', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Delete supplier error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menghapus supplier', null, 500);
        }
    }
}