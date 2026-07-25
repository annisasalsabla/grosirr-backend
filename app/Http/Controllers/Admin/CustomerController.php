<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
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
            ]);

            $customer = Customer::create($request->only(['name', 'phone', 'address']));

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
                'phone' => 'sometimes|string|max:15|unique:customers,phone,' . $id,
                'address' => 'sometimes|string',
            ]);

            $customer->update($request->only(['name', 'phone', 'address']));

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