<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Receivable;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

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
                'phone' => 'required|string|max:15',
                'address' => 'nullable|string',
                'is_setia' => 'boolean',
            ]);

            // Ambil semua input kecuali is_setia dan kolom status keanggotaan
            $data = $request->except([
                'is_setia',
                'member_status',
                'calon_member_since',
                'member_since',
                'rejection_note'
            ]);
            
            // Set default status baru sebagai 'umum'
            $data['member_status'] = 'umum';

            $customer = Customer::create($data);

            $this->logger->info('Customer created by Admin', [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'admin_id' => $request->user()->id
            ]);

            return $this->success($customer, 'Pelanggan berhasil ditambahkan', 201);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data pelanggan tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Create customer error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menambah pelanggan', null, 500);
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
            $customer = Customer::findOrFail($id);

            $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|string|max:15',
                'address' => 'nullable|string',
                'is_setia' => 'boolean',
            ]);

            // Ambil semua input kecuali is_setia dan kolom status keanggotaan
            $data = $request->except([
                'is_setia',
                'member_status',
                'calon_member_since',
                'member_since',
                'rejection_note'
            ]);

            $customer->update($data);

            $this->logger->info('Customer updated by Admin', [
                'customer_id' => $customer->id,
                'admin_id' => $request->user()->id
            ]);

            return $this->success($customer, 'Pelanggan berhasil diperbarui', 200);

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
     */
    public function destroy($id, Request $request)
    {
        try {
            $customer = Customer::findOrFail($id);

            // Cek apakah customer memiliki transaksi piutang yang belum lunas
            $unpaidReceivables = Receivable::where('customer_name', $customer->name)
                ->orWhere('customer_phone', $customer->phone)
                ->where('status', '!=', 'paid')
                ->exists();

            if ($unpaidReceivables) {
                return $this->error('Pelanggan tidak dapat dihapus karena masih memiliki piutang aktif', null, 400);
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

    /**
     * Get customers with active receivables (for monitoring).
     * GET /api/admin/customers/with-receivables
     */
    public function withReceivables(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);

            // Ambil customers yang memiliki piutang aktif
            $customerNames = Receivable::where('status', '!=', 'paid')
                ->distinct()
                ->pluck('customer_name');

            $customers = Customer::whereIn('name', $customerNames)
                ->orderBy('name')
                ->paginate($perPage);

            $customers->getCollection()->transform(function ($customer) {
                // Hitung total piutang customer ini
                $receivables = Receivable::where('customer_name', $customer->name)
                    ->where('status', '!=', 'paid')
                    ->get();

                $totalDebt = $receivables->sum('remaining_debt');
                $receivableCount = $receivables->count();

                // Cek ada yangjatuh tempo dalam 5 hari
                $approachingDue = $receivables->filter(function ($r) {
                    $dueDate = Carbon::parse($r->due_date);
                    $daysLeft = Carbon::now()->diffInDays($dueDate, false);
                    return $daysLeft <= 5 && $daysLeft >= 0;
                })->count();

                $customer->total_debt = $totalDebt;
                $customer->receivable_count = $receivableCount;
                $customer->approaching_due_count = $approachingDue;
                $customer->is_overdue = $receivables->filter(function ($r) {
                    return Carbon::parse($r->due_date)->isPast() && $r->status !== 'paid';
                })->count() > 0;

                return $customer;
            });

            return $this->success($customers, 'Daftar pelanggan dengan piutang berhasil dimuat', 200);

        } catch (\Exception $e) {
            $this->logger->error('Get customers with receivables error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat data pelanggan', null, 500);
        }
    }

    /**
     * Get customer history for autocomplete in transactions.
     * GET /api/admin/customers/search
     */
    public function search(Request $request)
    {
        try {
            $search = $request->input('q', '');

            if (strlen($search) < 2) {
                return $this->success([], 'Hasil pencarian', 200);
            }

            $customers = Customer::where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->limit(10)
                ->get(['id', 'name', 'phone', 'address']);

            return $this->success($customers, 'Hasil pencarian berhasil dimuat', 200);

        } catch (\Exception $e) {
            $this->logger->error('Search customers error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mencari pelanggan', null, 500);
        }
    }

    /**
     * Get list of customers waiting for member approval
     * GET /api/admin/customers/calon-member
     */
    public function calonMember(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);

            $customers = Customer::where('member_status', 'calon_member')
                ->orderBy('calon_member_since', 'desc')
                ->paginate($perPage);

            // Hitung statistik transaksi secara real-time
            $customers->getCollection()->transform(function ($customer) {
                $stats = DB::table('transactions')
                    ->where('customer_id', $customer->id)
                    ->selectRaw('COUNT(*) as total_transaksi, SUM(total_amount) as total_belanja')
                    ->first();

                $customer->total_transaksi = (int) $stats->total_transaksi;
                $customer->total_belanja = (float) ($stats->total_belanja ?? 0);
                return $customer;
            });

            return $this->success($customers, 'Daftar calon member berhasil dimuat', 200);
        } catch (\Exception $e) {
            $this->logger->error('Get calon member error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar calon member', null, 500);
        }
    }

    /**
     * Approve customer to become a member
     * POST /api/admin/customers/{id}/approve-member
     */
    public function approveMember(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $customer = Customer::where('id', $id)->lockForUpdate()->firstOrFail();

            if ($customer->member_status !== 'calon_member') {
                DB::rollBack();
                return $this->error('Pelanggan ini bukan calon member / sudah diproses.', null, 400);
            }

            $rules = [];
            
            if (empty($customer->phone)) {
                $rules['phone'] = 'required|string|max:15|unique:customers,phone,' . $id;
            } else {
                $rules['phone'] = 'nullable|string|max:15|unique:customers,phone,' . $id;
            }

            if (empty($customer->address)) {
                $rules['address'] = 'required|string';
            } else {
                $rules['address'] = 'nullable|string';
            }

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                DB::rollBack();
                return $this->validationError($validator->errors(), 'Data tidak lengkap untuk persetujuan member');
            }

            $customer->update([
                'member_status' => 'member',
                'member_since' => now(),
                'phone' => $request->input('phone', $customer->phone),
                'address' => $request->input('address', $customer->address),
                'is_ambiguous' => false, // Set to false if approved
            ]);

            DB::commit();

            $this->logger->info('Customer approved as member', [
                'customer_id' => $customer->id,
                'admin_id' => $request->user()->id
            ]);

            return $this->success($customer, 'Pelanggan berhasil disetujui sebagai member', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Approve member error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menyetujui member', null, 500);
        }
    }

    /**
     * Dismiss customer candidate status
     * POST /api/admin/customers/{id}/dismiss-candidate
     */
    public function dismissCandidate(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $customer = Customer::where('id', $id)->lockForUpdate()->firstOrFail();

            if ($customer->member_status !== 'calon_member') {
                DB::rollBack();
                return $this->error('Pelanggan ini bukan calon member / sudah diproses sebelumnya.', null, 400);
            }

            $customer->update([
                'member_status' => 'umum',
            ]);

            DB::commit();

            $this->logger->info('Customer candidate dismissed', [
                'customer_id' => $customer->id,
                'admin_id' => $request->user()->id
            ]);

            return $this->success($customer, 'Status calon member berhasil dihentikan (kembali ke umum)', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Dismiss candidate error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menghentikan calon member', null, 500);
        }
    }

    /**
     * Get duplicate candidates for ambiguous customer
     * GET /api/admin/customers/{id}/merge-candidates
     */
    public function getMergeCandidates($id)
    {
        try {
            $customer = Customer::findOrFail($id);
            
            if (!empty($customer->phone)) {
                return $this->success([], 'Pelanggan sudah memiliki No HP terverifikasi, bukan entitas ambigu.', 200);
            }
            
            $normalizedName = strtolower(trim($customer->name));
            
            $candidates = Customer::whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
                ->whereNull('phone')
                ->where('id', '!=', $customer->id)
                ->where('member_status', '!=', 'member')
                ->get();
                
            return $this->success($candidates, 'Kandidat merge berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get merge candidates error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar kandidat', null, 500);
        }
    }

    /**
     * Merge duplicate customer
     * POST /api/admin/customers/{id}/merge
     */
    public function merge(Request $request, $id)
    {
        $request->validate(['merge_from_id' => 'required|exists:customers,id']);
        
        if ($id == $request->merge_from_id) {
            return $this->error('ID pelanggan target dan sumber tidak boleh sama.', null, 400);
        }

        DB::beginTransaction();
        try {
            $ids = [(int) $id, (int) $request->merge_from_id];
            sort($ids);
            
            $first = Customer::where('id', $ids[0])->lockForUpdate()->firstOrFail();
            $second = Customer::where('id', $ids[1])->lockForUpdate()->firstOrFail();
            
            $targetCustomer = $first->id == $id ? $first : $second;
            $sourceCustomer = $first->id == $id ? $second : $first;
            
            if (strtolower(trim($targetCustomer->name)) !== strtolower(trim($sourceCustomer->name))) {
                DB::rollBack();
                return $this->error('Nama pelanggan target dan sumber tidak cocok. Tidak dapat menggabungkan entitas yang berbeda.', null, 400);
            }

            if ($sourceCustomer->member_status === 'member') {
                DB::rollBack();
                return $this->error('Pelanggan sumber sudah berstatus Member sah. Tidak dapat digabungkan.', null, 400);
            }
            if ($targetCustomer->member_status === 'member') {
                DB::rollBack();
                return $this->error('Pelanggan target sudah berstatus Member sah. Penggabungan hanya berlaku antar entitas ambigu/belum terverifikasi.', null, 400);
            }
            
            if (!empty($sourceCustomer->phone)) {
                DB::rollBack();
                return $this->error('Pelanggan sumber (merge_from_id) sudah memiliki Nomor HP terverifikasi. Merge hanya untuk membuang entitas ambigu (tanpa No HP).', null, 400);
            }
            
            \App\Models\Transaction::where('customer_id', $sourceCustomer->id)->update(['customer_id' => $targetCustomer->id]);
            Receivable::where('customer_id', $sourceCustomer->id)->update(['customer_id' => $targetCustomer->id]);
            
            $sourceCustomer->delete();
            
            if ($targetCustomer->member_status === 'umum') {
                \App\Models\Customer::evaluateMemberCandidacy($targetCustomer->id);
            }

            if (empty($targetCustomer->phone)) {
                $normalizedName = strtolower(trim($targetCustomer->name));
                $collisionCount = Customer::whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
                    ->whereNull('phone')
                    ->where('id', '!=', $targetCustomer->id)
                    ->where('member_status', '!=', 'member')
                    ->count();
                    
                if ($collisionCount === 0) {
                    $targetCustomer->update(['is_ambiguous' => false]);
                } else {
                    $targetCustomer->update(['is_ambiguous' => true]);
                }
            } else {
                $targetCustomer->update(['is_ambiguous' => false]);
            }
            
            DB::commit();
            
            $targetCustomer->refresh();
            return $this->success($targetCustomer, 'Data pelanggan berhasil digabung', 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Merge customer error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menggabungkan pelanggan', null, 500);
        }
    }
}