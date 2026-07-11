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

            $customer = Customer::create($request->all());

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
            $receivables = Receivable::where('customer_name', $customer->name)
                ->orWhere('customer_phone', $customer->phone)
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

            $customer->update($request->all());

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
}