<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Product;
use App\Models\Payable;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use App\Helpers\CloudinaryHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    public function addStock(Request $request)
    {
        DB::beginTransaction();

        try {
            // Debug log received data
            $this->logger->info('AddStock request received', [
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'bad_product_id' => $request->bad_product_id,
                'compensation_quantity' => $request->compensation_quantity,
            ]);

            $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
                // Validasi kompensasi baru
                'bad_product_id' => 'nullable|exists:bad_products,id',
                'compensation_quantity' => 'required_with:bad_product_id|integer|min:1|lte:quantity',
                // Jika kompensasi, parameter supplier/price tidak wajib
                'supplier_id' => 'required_without:bad_product_id|exists:suppliers,id',
                'purchase_price' => 'required_without:bad_product_id|numeric|min:0',
                'is_credit' => 'boolean',
                'due_date' => 'required_if:is_credit,true|date|after_or_equal:today',
                'bukti_pembelian' => 'required_without:bad_product_id|image|max:2048', // Opsional jika kompensasi
                'notes' => 'nullable|string|max:500',
                'tanggal_kompensasi' => 'nullable|date|before_or_equal:today',
            ]);
            
            // Ambil lock pada produk (proteksi race condition)
            $product = Product::where('id', $request->product_id)->lockForUpdate()->firstOrFail();
            
            $badProduct = null;
            $isKompensasi = $request->filled('bad_product_id');

            if ($isKompensasi) {
                // Ambil lock pada bad_product
                $badProduct = \App\Models\BadProduct::where('id', $request->bad_product_id)->lockForUpdate()->firstOrFail();
                
                // Cek apakah produknya sesuai
                if ($badProduct->product_id != $request->product_id) {
                    throw ValidationException::withMessages(['bad_product_id' => 'Barang rusak tidak sesuai dengan produk yang ditambahkan']);
                }

                // Cek apakah sudah selesai
                if ($badProduct->status_kompensasi === 'selesai') {
                    throw ValidationException::withMessages(['bad_product_id' => 'Kompensasi untuk barang rusak ini sudah selesai / lunas']);
                }

                // Validasi manual tanggal kompensasi vs incident_date
                $tanggalKompensasi = $request->filled('tanggal_kompensasi') ? \Carbon\Carbon::parse($request->tanggal_kompensasi)->startOfDay() : now()->startOfDay();
                if ($badProduct->incident_date && $tanggalKompensasi->lt($badProduct->incident_date->startOfDay())) {
                    throw ValidationException::withMessages(['tanggal_kompensasi' => 'Tanggal kompensasi tidak boleh lebih awal dari tanggal kejadian (' . $badProduct->incident_date->format('Y-m-d') . ')']);
                }

                // 1. Validasi Berbasis Nilai (Gabungan Barang + Uang)
                $unitValue = $badProduct->loss_amount / $badProduct->quantity;
                $state = \App\Models\BadProduct::calculateCompensationState($badProduct);
                $nilaiYangDitambahkan = $request->compensation_quantity * $unitValue;
                
                // Beri toleransi floating point sedikit (misal 1 rupiah) kalau perlu, tapi dengan round/number_format biasanya cukup aman jika loss_amount rapi.
                // Disini kita membulatkan dengan 2 desimal jika perlu, tapi karena mata uang, kita bisa membandingkan langsung.
                if (round($nilaiYangDitambahkan, 2) > round($state['sisa_nilai'], 2)) {
                    throw ValidationException::withMessages([
                        'compensation_quantity' => 'Kompensasi barang ini (senilai Rp ' . number_format($nilaiYangDitambahkan, 0, ',', '.') . ') melebihi sisa nilai kerugian yang belum terkompensasi (Rp ' . number_format($state['sisa_nilai'], 0, ',', '.') . ') - kemungkinan sebagian sudah dilunasi via potongan hutang.'
                    ]);
                }

                // 2. Validasi Berbasis Fisik / Unit
                $sisaUnit = $badProduct->quantity - $badProduct->compensated_quantity;
                if ($request->compensation_quantity > $sisaUnit) {
                    throw ValidationException::withMessages(['compensation_quantity' => 'Kuantitas kompensasi ('.$request->compensation_quantity.') melebihi sisa unit belum diganti ('.$sisaUnit.')']);
                }
            }
            
            $oldStock = $product->stock;
            $newStock = $request->quantity;
            
            $oldPrice = $product->purchase_price;
            $newPrice = $isKompensasi ? $oldPrice : $request->purchase_price; // Bekukan harga jika kompensasi
            
            // 2. Hitung Moving Average (Kecuali Kompensasi)
            $averagePrice = $oldPrice;
            if (!$isKompensasi) {
                $totalValueOld = $oldStock * $oldPrice;
                $totalValueNew = $newStock * $newPrice;
                
                if ($oldStock + $newStock > 0) {
                    $averagePrice = ($totalValueOld + $totalValueNew) / ($oldStock + $newStock);
                }
            }

            // 3. Simpan harga baru & increment stok
            $product->purchase_price = round($averagePrice, 0);
            $product->stock = $oldStock + $newStock;
            $product->save();

            // Simpan bukti pembelian (Jika ada)
            $buktiPembelianPath = null;
            if ($request->hasFile('bukti_pembelian')) {
                $buktiPembelianPath = CloudinaryHelper::upload($request->file('bukti_pembelian'), 'bukti-pembelian');
            }
            
            // Tentukan Supplier & Deskripsi
            $supplierId = $isKompensasi ? $badProduct->product->supplier_id : $request->supplier_id;
            $description = $isKompensasi 
                ? 'Kompensasi barang dari supplier (Ref BP ID: ' . $badProduct->id . ')' 
                : 'Pembelian dari supplier: ' . $supplierId;

            // Tentukan harga yang dicatat ke tabel stocks (Kompensasi = 0)
            $recordedPrice = $isKompensasi ? 0 : $request->purchase_price;

            // Catat stok masuk
            $stock = Stock::create([
                'product_id' => $product->id,
                'type' => 'in',
                'quantity' => $newStock,
                'purchase_price' => $recordedPrice,
                'is_credit' => $isKompensasi ? false : ($request->is_credit ?? false), // Paksa false jika kompensasi
                'due_date' => $request->due_date,
                'supplier_id' => $supplierId,
                'description' => $description,
                'user_id' => $request->user()->id,
                'bukti_pembelian' => $buktiPembelianPath,
            ]);
            
            // Jika kredit (BUKAN kompensasi), catat hutang
            $payable = null;
            if ($request->is_credit && !$isKompensasi) {
                $totalPrice = $recordedPrice * $newStock;
                $payable = Payable::create([
                    'supplier_id' => $supplierId,
                    'total_debt' => $totalPrice,
                    'paid_amount' => 0,
                    'remaining_debt' => $totalPrice,
                    'due_date' => $request->due_date,
                    'status' => 'unpaid',
                ]);
            }
            
            // Eksekusi Update Status Kompensasi
            if ($isKompensasi) {
                $badProduct->compensated_quantity += $request->compensation_quantity;
                
                $state = \App\Models\BadProduct::calculateCompensationState($badProduct);
                $badProduct->status_kompensasi = $state['status'];
                
                // Catat history dalam format JSON via helper
                $badProduct->appendKompensasiHistory([
                    'tanggal' => $tanggalKompensasi->format('Y-m-d'),
                    'jenis' => 'barang',
                    'nominal' => null,
                    'jumlah' => (float)$request->compensation_quantity,
                    'unit' => $product->unit,
                    'catatan' => $request->notes,
                    'image_url' => $stock->bukti_pembelian_url
                ]);
                    
                $badProduct->tanggal_kompensasi = $tanggalKompensasi;
                $badProduct->save();
            }
            
            DB::commit();
            
            return $this->success([
                'product' => $product,
                'stock' => $stock,
                'payable' => $payable,
                'bad_product' => $badProduct
            ], 'Stok berhasil ditambahkan', 201);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors(), 'Data stok tidak valid');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Add stock error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menambah stok', null, 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);

            // Filter tanggal: ?date=YYYY-MM-DD atau hari ini jika tidak ada
            $query = Stock::with(['product', 'user', 'supplier'])
                ->where('type', 'in');

            if ($request->has('date')) {
                $query->whereDate('created_at', $request->date);
            } else {
                // Default: hari ini (WIB timezone)
                $query->whereDate('created_at', now('Asia/Jakarta')->toDateString());
            }

            $stocks = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return $this->success($stocks, 'Riwayat stok berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get stocks error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat riwayat stok', null, 500);
        }
    }

    public function history(Request $request)
    {
        return $this->index($request);
    }
}