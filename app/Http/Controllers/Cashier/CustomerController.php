<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponseTrait;

    /**
     * List customers for cashier dropdown selection
     * GET /api/cashier/customers
     *
     * Returns: id, name, phone, address (for dropdown)
     * Simple pagination: per_page 100 (dropdown doesn't need load-more)
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 100);

            $customers = Customer::orderBy('name')
                ->paginate($perPage);

            // Format response untuk dropdown
            $customers->getCollection()->transform(function ($customer) {
                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'address' => $customer->address,
                ];
            });

            return $this->success($customers, 'Daftar pelanggan berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan saat memuat daftar pelanggan', null, 500);
        }
    }

    /**
     * Get credit info for a specific customer
     * GET /api/cashier/customers/{id}/credit-info
     */
    public function creditInfo($id)
    {
        try {
            $customer = Customer::findOrFail($id);
            
            $totalPiutangAktif = (float)($customer->receivables()->where('remaining_debt', '>', 0)->sum('remaining_debt') ?? 0);
            $sisaLimit = max(0, $customer->credit_limit - $totalPiutangAktif);
            
            return $this->success([
                'credit_limit' => (float) $customer->credit_limit,
                'total_piutang_aktif' => $totalPiutangAktif,
                'sisa_limit' => $sisaLimit
            ], 'Info kredit berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Pelanggan tidak ditemukan', null, 404);
        }
    }
}