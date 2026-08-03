<?php

namespace App\Http\Controllers\Member;

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
        $customer = $request->user()->customer;
        if (!$customer) return $this->error('Data profil customer tidak ditemukan', null, 404);

        $requests = DB::table('item_requests')
            ->where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
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

    public function store(Request $request)
    {
        try {
            $customer = $request->user()->customer;
            if (!$customer) return $this->error('Data profil customer tidak ditemukan', null, 404);

            $request->validate([
                'notes' => 'nullable|string|max:1000',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
            ]);

            DB::beginTransaction();
            try {
                $itemRequestId = DB::table('item_requests')->insertGetId([
                    'customer_id' => $customer->id,
                    'notes' => $request->notes,
                    'status' => 'menunggu',
                    'created_at' => now('Asia/Jakarta'),
                    'updated_at' => now('Asia/Jakarta')
                ]);

                $details = [];
                foreach ($request->items as $item) {
                    $details[] = [
                        'item_request_id' => $itemRequestId,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'created_at' => now('Asia/Jakarta'),
                        'updated_at' => now('Asia/Jakarta')
                    ];
                }
                
                DB::table('item_request_details')->insert($details);

                DB::commit();
                
                return $this->success(null, 'Pengajuan barang berhasil dikirim', 201);
                
            } catch (\Exception $e) {
                DB::rollBack();
                return $this->error('Gagal mengirim pengajuan: ' . $e->getMessage(), null, 500);
            }
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data tidak valid');
        }
    }
}
