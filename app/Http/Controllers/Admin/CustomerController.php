<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Receivable;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Display a listing of customers.
     * GET /api/admin/customers
     *
     * Supports: ?search=keyword (by name/phone), ?q=keyword (alias), ?per_page=10
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search') ?? $request->input('q');

            $query = Customer::query();

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            $customers = $query->orderBy('name')->paginate($perPage);

            // Tambahkan info total piutang per customer
            $customers->getCollection()->transform(function ($customer) {
                $customer->total_receivable = $customer->getTotalReceivable();
                $customer->unpaid_transactions_count = $customer->transactions()
                    ->where('payment_method', 'receivable')
                    ->where('payment_status', '!=', 'paid')
                    ->count();
                return $customer;
            });

            return $this->success($customers, 'Daftar pelanggan berhasil dimuat', 200);

        } catch (\Exception $e) {
            $this->logger->error('Get customers error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar pelanggan', null, 500);
        }
    }

    /**
     * Store a newly created customer.
     * POST /api/admin/customers
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'required|string|max:15|unique:customers,phone',
                'address' => 'required|string',
                'credit_limit' => 'sometimes|numeric|min:0',
                
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
                        'role' => 'member',
                        'is_active' => true,
                    ]);
                    $userId = $user->id;
                }

                $customer = Customer::create(array_merge(
                    $request->only(['name', 'phone', 'address', 'credit_limit']),
                    ['user_id' => $userId]
                ));

                DB::commit();

                $this->logger->info('Customer created by Admin', [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'admin_id' => $request->user()->id
                ]);

                return $this->success($customer, 'Pelanggan berhasil ditambahkan', 201);
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->logger->error('Create customer error: ' . $e->getMessage());
                return $this->error('Terjadi kesalahan saat menambah pelanggan', null, 500);
            }

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data pelanggan tidak valid');
        }
    }

    /**
     * Display the specified customer.
     * GET /api/admin/customers/{id}
     */
    public function show($id)
    {
        try {
            $customer = Customer::with(['transactions' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(10);
            }])->findOrFail($id);

            $customer->total_receivable = $customer->getTotalReceivable();
            
            $totalPiutangAktif = (float)($customer->receivables()->where('remaining_debt', '>', 0)->sum('remaining_debt') ?? 0);
            $customer->sisa_limit = max(0, $customer->credit_limit - $totalPiutangAktif);

            // Ambil riwayat piutang customer
            $receivables = Receivable::where('customer_id', $customer->id)
                ->with('transaction')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            $customer->receivables_history = $receivables;

            return $this->success($customer, 'Detail pelanggan berhasil dimuat', 200);

        } catch (\Exception $e) {
            $this->logger->error('Show customer error: ' . $e->getMessage());
            return $this->error('Pelanggan tidak ditemukan', null, 404);
        }
    }

    /**
     * Update the specified customer.
     * PUT /api/admin/customers/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $customer = Customer::with('user')->findOrFail($id);
            $userId = $customer->user_id;

            $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|string|max:15|unique:customers,phone,' . $id,
                'address' => 'sometimes|string',
                'credit_limit' => 'sometimes|numeric|min:0',
                
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
                $totalPiutangAktif = (float)($customer->receivables()->where('remaining_debt', '>', 0)->sum('remaining_debt') ?? 0);
                
                $warning = null;
                if ($request->has('credit_limit') && $request->credit_limit < $totalPiutangAktif) {
                    $limitBaru = number_format($request->credit_limit, 0, ',', '.');
                    $aktif = number_format($totalPiutangAktif, 0, ',', '.');
                    $warning = "Limit baru (Rp {$limitBaru}) lebih rendah dari piutang aktif member saat ini (Rp {$aktif}). Member ini tidak bisa transaksi kredit baru sampai piutangnya berkurang.";
                }

                $customer->update($request->only(['name', 'phone', 'address', 'credit_limit']));

                if ($request->filled('username') || $request->filled('password')) {
                    if ($userId) {
                        $user = $customer->user; 
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
                        if ($request->filled('username')) {
                            $user = User::create([
                                'name' => $request->name ?? $customer->name, 
                                'username' => $request->username,
                                'password' => Hash::make($request->password),
                                'role' => 'member',
                                'is_active' => true,
                            ]);
                            $customer->user_id = $user->id;
                            $customer->save();
                        }
                    }
                }

                DB::commit();

                $this->logger->info('Customer updated by Admin', [
                    'customer_id' => $customer->id,
                    'admin_id' => $request->user()->id
                ]);

                $response = [
                    'success' => true,
                    'message' => 'Pelanggan berhasil diperbarui',
                    'data' => $customer,
                    'code' => 200
                ];
                
                if ($warning) {
                    $response['warning'] = $warning;
                }

                return response()->json($response, 200);

            } catch (\Exception $e) {
                DB::rollBack();
                $this->logger->error('Transaction error in Customer Update: ' . $e->getMessage());
                return $this->error('Terjadi kesalahan saat memproses data akun/profil', null, 500);
            }

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data pelanggan tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Update customer error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memperbarui pelanggan', null, 500);
        }
    }

    /**
     * Remove the specified customer.
     * DELETE /api/admin/customers/{id}
     *
     * Tolak jika customer masih memiliki relasi ke transactions atau receivables.
     */
    public function destroy($id, Request $request)
    {
        try {
            $customer = Customer::findOrFail($id);

            // Cek apakah customer memiliki transaksi terkait
            $hasTransactions = $customer->transactions()->exists();

            // Cek apakah customer memiliki piutang terkait
            $hasReceivables = Receivable::where('customer_id', $customer->id)->exists();

            if ($hasTransactions || $hasReceivables) {
                return $this->error(
                    'Tidak dapat menghapus, pelanggan ini masih memiliki riwayat transaksi atau piutang aktif',
                    null,
                    409
                );
            }

            $customerName = $customer->name;
            $customer->delete();

            $this->logger->info('Customer deleted by Admin', [
                'customer_id' => $id,
                'customer_name' => $customerName,
                'admin_id' => $request->user()->id
            ]);

            return $this->success(null, 'Pelanggan berhasil dihapus', 200);

        } catch (\Exception $e) {
            $this->logger->error('Delete customer error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menghapus pelanggan', null, 500);
        }
    }
}