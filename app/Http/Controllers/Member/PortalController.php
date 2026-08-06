<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use App\Models\Transaction;
use App\Models\Receivable;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    use ApiResponseTrait;

    public function getTransactions(Request $request)
    {
        $customer = $request->user()->customer;
        if (!$customer) return $this->error('Data profil customer tidak ditemukan', null, 404);

        $transactions = Transaction::where('customer_id', $customer->id)
            ->with('details.product:id,name,unit,unit_type')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($transactions, 'Riwayat transaksi berhasil dimuat');
    }

    public function getReceivables(Request $request)
    {
        $customer = $request->user()->customer;
        if (!$customer) return $this->error('Data profil customer tidak ditemukan', null, 404);

        $receivables = Receivable::where('customer_id', $customer->id)
            ->with(['transaction:id,invoice_number', 'customer'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($receivable) {
                if ($receivable->customer) {
                    $receivable->customer_name = $receivable->customer->name;
                    $receivable->customer_phone = $receivable->customer->phone;
                    $receivable->customer_address = $receivable->customer->address;
                }
                unset($receivable->customer);
                return $receivable;
            });

        return $this->success($receivables, 'Riwayat piutang berhasil dimuat');
    }

    public function getProfile(Request $request)
    {
        $user = $request->user()->load('customer');
        if (!$user->customer) {
            return $this->error('Data profil pelanggan tidak ditemukan', null, 404);
        }
        $customerData = $user->customer;
        
        return $this->success([
            'id' => $customerData->id,
            'name' => $customerData->name,
            'phone' => $customerData->phone,
            'address' => $customerData->address,
            'email' => $user->email,
            'username' => $user->username,
            'is_active' => $user->is_active,
            'member_since' => $customerData->created_at,
        ], 'Data profil berhasil dimuat');
    }
}
