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
            ->with('transaction:id,invoice_number')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($receivables, 'Riwayat piutang berhasil dimuat');
    }
}
