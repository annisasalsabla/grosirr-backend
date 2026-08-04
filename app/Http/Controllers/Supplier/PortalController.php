<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use App\Models\Stock;
use App\Models\Payable;
use App\Models\BadProduct;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    use ApiResponseTrait;

    public function getDeliveries(Request $request)
    {
        $supplier = $request->user()->supplier;
        if (!$supplier) return $this->error('Data profil supplier tidak ditemukan', null, 404);

        $deliveries = Stock::where('supplier_id', $supplier->id)
            ->with('product:id,name,category,unit,unit_type')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($deliveries, 'Data pengiriman berhasil dimuat');
    }

    public function getPayables(Request $request)
    {
        $supplier = $request->user()->supplier;
        if (!$supplier) return $this->error('Data profil supplier tidak ditemukan', null, 404);

        $payables = Payable::where('supplier_id', $supplier->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($payables, 'Data hutang berhasil dimuat');
    }

    public function getBadProducts(Request $request)
    {
        $supplier = $request->user()->supplier;
        if (!$supplier) return $this->error('Data profil supplier tidak ditemukan', null, 404);

        $badProducts = BadProduct::whereHas('product', function($q) use ($supplier) {
                $q->where('supplier_id', $supplier->id);
            })
            ->with('product:id,name,unit,unit_type')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($badProducts, 'Data produk rusak terkait berhasil dimuat');
    }
}
