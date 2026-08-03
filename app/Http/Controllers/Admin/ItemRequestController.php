<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemRequestController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request)
    {
        $requests = DB::table('item_requests')
            ->join('customers', 'item_requests.customer_id', '=', 'customers.id')
            ->select('item_requests.*', 'customers.name as customer_name')
            ->orderBy('item_requests.created_at', 'desc')
            ->get();

        foreach ($requests as $req) {
            $req->details = DB::table('item_request_details')
                ->join('products', 'item_request_details.product_id', '=', 'products.id')
                ->where('item_request_id', $req->id)
                ->select('item_request_details.*', 'products.name as product_name')
                ->get();
        }

        return $this->success($requests, 'Daftar pengajuan barang berhasil dimuat');
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|in:selesai,dibatalkan'
            ]);

            $itemRequest = DB::table('item_requests')->where('id', $id)->first();
            if (!$itemRequest) {
                return $this->error('Pengajuan barang tidak ditemukan', null, 404);
            }

            DB::table('item_requests')->where('id', $id)->update([
                'status' => $request->status,
                'updated_at' => now('Asia/Jakarta')
            ]);

            return $this->success(null, 'Status pengajuan berhasil diperbarui');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data tidak valid');
        } catch (\Exception $e) {
            return $this->error('Gagal memperbarui status: ' . $e->getMessage(), null, 500);
        }
    }
}
