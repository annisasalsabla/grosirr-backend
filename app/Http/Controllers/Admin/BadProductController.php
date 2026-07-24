<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BadProduct;
use App\Models\Product;
use App\Models\Supplier;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use App\Services\BadProductCalculator;
use App\Helpers\CloudinaryHelper;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf as PDF;

class BadProductController extends Controller
{
    use \App\Traits\SupplierComparisonTrait;

    use ApiResponseTrait, \App\Traits\DateRangeHelper;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Helper untuk menerapkan filter period dan status secara seragam
     * pada semua endpoint pencarian data barang rusak.
     */
    private function applyFilters($query, Request $request)
    {
        $status = $request->input('status');

        // FILTER STATUS SESUAI TAB (DISPLAY_STATUS)
        if ($status === 'selesai') {
            $query->where('status_kompensasi', 'selesai');
        } elseif ($status === 'menunggu_kompensasi') {
            $query->where('status_kompensasi', '!=', 'selesai')
                  ->where(function($q) {
                      $q->where('status_kompensasi', 'diganti_sebagian')
                        ->orWhere('reported_to_supplier', true)
                        ->orWhere('status', 'reported');
                  });
        } elseif ($status === 'belum_dilaporkan') {
            $query->where('status_kompensasi', '!=', 'selesai')
                  ->where('status_kompensasi', '!=', 'diganti_sebagian')
                  ->where('reported_to_supplier', false)
                  ->where('status', '!=', 'reported');
        }

        // FILTER PERIODE
        if ($request->has('period')) {
            switch ($request->period) {
                case 'daily':
                    $date = $request->input('date', now()->toDateString());
                    $query->where('tanggal_kejadian', $date);
                    break;
                case 'weekly':
                    $week  = $request->input('week');
                    $month = $request->input('month', now()->month);
                    $year  = $request->input('year', now()->year);

                    if ($week !== null) {
                        $range = $this->getFlutterWeeklyRange($week, $month, $year);
                        $startDate = $range['start'];
                        $endDate   = $range['end'];
                    } else {
                        $baseDate  = \Carbon\Carbon::parse($request->input('date', now()->toDateString()));
                        $startDate = $baseDate->copy()->startOfWeek()->toDateString();
                        $endDate   = $baseDate->copy()->endOfWeek()->toDateString();
                    }
                    $query->whereBetween('tanggal_kejadian', [$startDate, $endDate]);
                    break;
                case 'monthly':
                    $month = $request->input('month', now()->month);
                    $year = $request->input('year', now()->year);
                    $startOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
                    $endOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
                    $query->whereBetween('tanggal_kejadian', [$startOfMonth, $endOfMonth]);
                    break;
                case 'custom':
                    $query->whereBetween('tanggal_kejadian', [$request->start_date, $request->end_date]);
                    break;
            }
        }

        return $query;
    }


    /**
     * Display a listing of all bad products (Global Pagination)
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->integer('per_page', 10);
            $status = $request->input('status');

            $query = BadProduct::with(['product', 'reportedBy']);

            $this->applyFilters($query, $request);

            // HITUNG SUMMARY DINAMIS (Berdasarkan periode)
            // Clone agar summary tidak terkena efek paginate(limit/offset)
            $summaryQuery = clone $query;
            $allBadProductsForSummary = $summaryQuery->get();
            
            $summary = [
                'total_items' => $allBadProductsForSummary->count(),
                'total_quantity' => $allBadProductsForSummary->sum('quantity'),
                'total_loss' => $allBadProductsForSummary->sum('loss_amount'),
                'status_counts' => [
                    'belum_dilaporkan' => 0,
                    'menunggu_kompensasi' => 0,
                    'selesai' => 0
                ]
            ];
            
            foreach ($allBadProductsForSummary as $bp) {
                $displayStatus = $bp->display_status;
                if (isset($summary['status_counts'][$displayStatus])) {
                    $summary['status_counts'][$displayStatus]++;
                }
            }

            $badProducts = $query->orderBy('tanggal_kejadian', 'desc')->paginate($perPage);

            return $this->success([
                'bad_products' => $badProducts,
                'summary' => $summary
            ], 'Daftar barang rusak berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get bad products error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar barang rusak', null, 500);
        }
    }



    /**
     * Store data barang rusak baru
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
                'unit' => 'required|string|max:50',
                'damage_reason' => 'required|string',
                'tanggal_kejadian' => 'required|date|before_or_equal:today',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ]);
            
            $product = Product::findOrFail($request->product_id);
            
            if ($product->stock < $request->quantity) {
                return $this->error('Stok produk tidak mencukupi untuk mencatat barang rusak', null, 400);
            }
            
            // Hitung loss amount berdasarkan satuan
            $calculation = BadProductCalculator::getCalculationDetail(
                $product,
                $request->quantity,
                $request->unit
            );
            
            $lossAmount = $calculation['loss_amount'];
            
            // Upload gambar jika ada
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = CloudinaryHelper::upload($request->file('image'), 'bad-products');
            }
            
            $badProduct = BadProduct::create([
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'unit' => $request->unit,
                'damage_reason' => $request->damage_reason,
                'image' => $imagePath,
                'loss_amount' => $lossAmount,
                'incident_date' => $request->tanggal_kejadian,
                'tanggal_kejadian' => $request->tanggal_kejadian,
                'reported_by' => $request->user()->id,
                'status' => 'pending',
            ]);
            
            // Kurangi stok
            $product->decrement('stock', $request->quantity);
            
            // Catat histori stok
            \App\Models\Stock::create([
                'product_id' => $product->id,
                'type' => 'out',
                'quantity' => $request->quantity,
                'description' => 'Barang rusak: ' . $request->damage_reason . ' (' . $request->quantity . ' ' . $request->unit . ')',
                'user_id' => $request->user()->id,
            ]);
            
            DB::commit();
            
            return $this->success([
                'bad_product' => $badProduct,
                'calculation' => $calculation,
            ], 'Barang rusak berhasil dicatat', 201);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors(), 'Data barang rusak tidak valid');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Create bad product error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mencatat barang rusak', null, 500);
        }
    }

    /**
     * Display the specified bad product
     */
    public function show($id)
    {
        try {
            $badProduct = BadProduct::with(['product:id,name,category,unit,purchase_price,supplier_id', 'reportedBy'])->findOrFail($id);
            
            // Kalkulasi state kompensasi terbaru
            $state = \App\Models\BadProduct::calculateCompensationState($badProduct);
            
            // Kalkulasi field bantuan untuk Frontend (Validasi 2-Gerbang)
            $unitValue = $badProduct->quantity > 0 ? ($badProduct->loss_amount / $badProduct->quantity) : 0;
            
            $remainingByUnit = max(0, $badProduct->quantity - $badProduct->compensated_quantity);
            $remainingByValue = $unitValue > 0 ? floor($state['sisa_nilai'] / $unitValue) : 0;
            $remainingQuantity = min($remainingByUnit, $remainingByValue);
            
            // Siapkan array data bad_product dengan tambahan field kalkulasi
            $badProductData = $badProduct->toArray();
            
            // Tambahkan field turunan secara eksplisit
            $badProductData['calculated_status'] = $state['status'];
            $badProductData['sisa_nilai'] = $state['sisa_nilai'];
            $badProductData['unit_value'] = $unitValue;
            $badProductData['remaining_quantity'] = $remainingQuantity;
            
            return $this->success([
                'bad_product' => $badProductData,
            ], 'Detail barang rusak berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            return $this->error('Barang rusak tidak ditemukan', null, 404);
        }
    }

    /**
     * UPDATE barang rusak (Edit)
     * PUT /api/admin/bad-products/{id}
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $badProduct = BadProduct::with(['product'])->findOrFail($id);
            $oldProduct = $badProduct->product;
            $oldQuantity = $badProduct->quantity;
            
            $request->validate([
                'product_id' => 'sometimes|exists:products,id',
                'quantity' => 'sometimes|integer|min:1',
                'unit' => 'sometimes|string|max:50',
                'damage_reason' => 'sometimes|string',
                'incident_date' => 'sometimes|date',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'delete_image' => 'sometimes|boolean',
            ]);
            
            // Jika product_id berubah, ambil produk baru
            $productId = $request->product_id ?? $badProduct->product_id;
            $product = Product::findOrFail($productId);
            
            // Hitung quantity dan unit baru
            $newQuantity = $request->quantity ?? $badProduct->quantity;
            $newUnit = $request->unit ?? $badProduct->unit ?? $product->unit;
            
            // ========== KEMBALIKAN STOK LAMA ==========
            // Kembalikan stok produk lama ke jumlah sebelum dicatat
            $oldProduct->increment('stock', $oldQuantity);
            
            // Hapus histori stok lama (optional, kita buat baru nanti)
            \App\Models\Stock::where('product_id', $oldProduct->id)
                ->where('description', 'like', '%Barang rusak%')
                ->where('created_at', '>=', $badProduct->created_at)
                ->delete();
            
            // ========== HITUNG KERUGIAN BARU ==========
            $calculation = BadProductCalculator::getCalculationDetail(
                $product,
                $newQuantity,
                $newUnit
            );
            
            $lossAmount = $calculation['loss_amount'];
            
            // ========== UPDATE STOK PRODUK BARU ==========
            // Kurangi stok produk baru
            if ($product->stock < $newQuantity) {
                throw new \Exception('Stok produk tidak mencukupi');
            }
            $product->decrement('stock', $newQuantity);
            
            // Catat histori stok baru
            \App\Models\Stock::create([
                'product_id' => $product->id,
                'type' => 'out',
                'quantity' => $newQuantity,
                'description' => 'Barang rusak (update): ' . ($request->damage_reason ?? $badProduct->damage_reason) . ' (' . $newQuantity . ' ' . $newUnit . ')',
                'user_id' => $request->user()->id,
            ]);
            
            // ========== UPDATE GAMBAR ==========
            // Hapus gambar lama jika diminta
            if ($request->boolean('delete_image') && $badProduct->image) {
                CloudinaryHelper::delete($badProduct->image);
                $badProduct->image = null;
            }
            
            // Upload gambar baru
            if ($request->hasFile('image')) {
                // Hapus gambar lama jika ada
                if ($badProduct->image) {
                    CloudinaryHelper::delete($badProduct->image);
                }
                $badProduct->image = CloudinaryHelper::upload($request->file('image'), 'bad-products');
            }
            
            // Update data
            $badProduct->product_id = $productId;
            $badProduct->quantity = $newQuantity;
            $badProduct->unit = $newUnit;
            $badProduct->loss_amount = $lossAmount;
            
            if ($request->has('damage_reason')) {
                $badProduct->damage_reason = $request->damage_reason;
            }
            
            if ($request->has('incident_date')) {
                $badProduct->incident_date = $request->incident_date;
            }
            
            $badProduct->save();
            
            DB::commit();
            
            $this->logger->info('Barang rusak berhasil diupdate', [
                'bad_product_id' => $badProduct->id,
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success([
                'bad_product' => $badProduct,
                'calculation' => $calculation,
            ], 'Barang rusak berhasil diperbarui', 200);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors(), 'Data barang rusak tidak valid');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Update bad product error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memperbarui barang rusak: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * DELETE barang rusak (Hapus manual dari database)
     * Catatan: Stok TIDAK dikembalikan
     * DELETE /api/admin/bad-products/{id}
     */
    public function destroy($id, Request $request)
    {
        try {
            $badProduct = BadProduct::findOrFail($id);

            // Hapus gambar jika ada
            if ($badProduct->image) {
                CloudinaryHelper::delete($badProduct->image);
            }

            // Hapus data
            $badProduct->delete();

            $this->logger->info('Barang rusak dihapus oleh Admin', [
                'bad_product_id' => $id,
                'admin_id' => $request->user()->id
            ]);

            return $this->success(null, 'Barang rusak berhasil dihapus', 200);

        } catch (\Exception $e) {
            $this->logger->error('Delete bad product error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menghapus barang rusak', null, 500);
        }
    }

    /**
     * GET suppliers with bad products (untuk tab)
     */
    public function getSuppliersWithBadProducts(Request $request)
    {
        try {
            $suppliers = Supplier::whereHas('products', function ($query) use ($request) {
                $query->whereHas('badProducts', function ($q) use ($request) {
                    $this->applyFilters($q, $request);
                });
            })->get();
            
            $result = [];
            
            foreach ($suppliers as $supplier) {
                $query = BadProduct::with(['product'])
                    ->whereHas('product', function ($q) use ($supplier) {
                        $q->where('supplier_id', $supplier->id);
                    });
                
                $this->applyFilters($query, $request);
                
                $badProducts = $query->orderBy('tanggal_kejadian', 'desc')->get();
                
                $totalQuantity = 0;
                $totalLoss = 0;
                
                $formattedBadProducts = [];
                foreach ($badProducts as $bp) {
                    $totalQuantity += (int) $bp->quantity;
                    $totalLoss += (float) $bp->loss_amount;
                    
                    $formattedBadProducts[] = [
                        'id' => $bp->id,
                        'product_id' => $bp->product_id,
                        'product_name' => $bp->product->name ?? '-',
                        'unit' => $bp->unit ?? $bp->product->unit,
                        'quantity' => $bp->quantity,
                        'damage_reason' => $bp->damage_reason,
                        'loss_amount' => $bp->loss_amount,
                        'incident_date' => $bp->incident_date,
                        'tanggal_kejadian' => $bp->tanggal_kejadian,
                        'tanggal_kejadian_formatted' => $bp->tanggal_kejadian ? $bp->tanggal_kejadian->format('d/m/Y') : null,
                        'image' => $bp->image,
                        'image_url' => $bp->image_url,
                        'status' => $bp->status ?? 'pending',
                        'reported_at' => $bp->reported_at,
                        'reported_to_supplier' => $bp->reported_to_supplier,
                        'created_at' => $bp->created_at,
                    ];
                }
                
                $result[] = [
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'supplier_phone' => $supplier->phone,
                    'supplier_address' => $supplier->address,
                    'bad_products' => $formattedBadProducts,
                    'summary' => [
                        'total_quantity' => $totalQuantity,
                        'total_loss' => $totalLoss,
                        'total_items' => $badProducts->count(),
                    ],
                ];
            }
            
            return $this->success($result, 'Daftar supplier dengan barang rusak berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get suppliers with bad products error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat data', null, 500);
        }
    }

    /**
     * GET bad products by supplier
     */
    public function getBySupplier($supplierId, Request $request)
    {
        try {
            $supplier = Supplier::find($supplierId);
            
            if (!$supplier) {
                return $this->error('Supplier tidak ditemukan', null, 404);
            }
            
            $query = BadProduct::with(['product'])
                ->whereHas('product', function ($q) use ($supplierId) {
                    $q->where('supplier_id', $supplierId);
                });
                
            $this->applyFilters($query, $request);
            
            $badProducts = $query->orderBy('tanggal_kejadian', 'desc')->get();
            
            $totalQuantity = 0;
            $totalLoss = 0;
            
            $formattedBadProducts = [];
            foreach ($badProducts as $item) {
                $totalQuantity += (int) $item->quantity;
                $totalLoss += (float) $item->loss_amount;

                $formattedBadProducts[] = [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? '-',
                    'unit' => $item->unit ?? $item->product->unit,
                    'quantity' => $item->quantity,
                    'damage_reason' => $item->damage_reason,
                    'loss_amount' => $item->loss_amount,
                    'incident_date' => $item->incident_date,
                    'tanggal_kejadian' => $item->tanggal_kejadian,
                    'tanggal_kejadian_formatted' => $item->tanggal_kejadian ? $item->tanggal_kejadian->format('d/m/Y') : null,
                    'image' => $item->image,
                    'image_url' => $item->image_url,
                    'status' => $item->status ?? 'pending',
                    'reported_at' => $item->reported_at,
                    'reported_to_supplier' => $item->reported_to_supplier,
                    'created_at' => $item->created_at,
                ];
            }
            
            $summary = [
                'total_quantity' => $totalQuantity,
                'total_loss' => $totalLoss,
                'total_items' => $badProducts->count(),
            ];
            
            return $this->success([
                'supplier' => $supplier,
                'bad_products' => $formattedBadProducts,
                'summary' => $summary,
            ], 'Data barang rusak untuk supplier berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get bad products by supplier error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat data', null, 500);
        }
    }

    /**
     * EXPORT PDF dan update status untuk barang rusak supplier
     * Catatan: Data TIDAK dihapus, hanya update status='reported'
     */
    public function exportPdfBySupplier($supplierId, Request $request)
    {
        try {
            $supplier = Supplier::find($supplierId);

            if (!$supplier) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supplier tidak ditemukan',
                    'code' => 404
                ], 404);
            }

            // Ambil data barang rusak berdasarkan filter periode dan status
            $query = BadProduct::with(['product'])
                ->whereHas('product', function ($q) use ($supplierId) {
                    $q->where('supplier_id', $supplierId);
                });
            
            // Fallback keamanan: jika request tidak membawa filter status eksplisit,
            // paksa set status menjadi "belum_dilaporkan" (sesuai logic display_status).
            if (!$request->has('status')) {
                $request->merge(['status' => 'belum_dilaporkan']);
            }
            
            $this->applyFilters($query, $request);

            $badProducts = $query->get();

            if ($badProducts->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data barang rusak dengan status pending untuk dicetak',
                    'code' => 400
                ], 400);
            }

            // Hitung summary
            $totalQuantity = 0;
            $totalLoss = 0;

            foreach ($badProducts as $item) {
                $totalQuantity += (int) $item->quantity;
                $totalLoss += (float) $item->loss_amount;
            }

            $summary = [
                'total_quantity' => $totalQuantity,
                'total_loss' => $totalLoss,
                'total_items' => $badProducts->count(),
                'formatted_total_loss' => 'Rp ' . number_format($totalLoss, 0, ',', '.'),
            ];

            // Siapkan data untuk PDF
            $data = [
                'supplier' => $supplier,
                'badProducts' => $badProducts,
                'summary' => $summary,
                'date' => now(),
                'report_number' => 'LBR/' . date('Ymd') . '/' . str_pad($supplierId, 3, '0', STR_PAD_LEFT),
            ];

            // Generate PDF
            $pdf = PDF::loadView('pdf.bad-product-report', $data);
            $pdf->setPaper('A4', 'portrait');

            $filename = 'laporan_barang_rusak_' . preg_replace('/[^a-zA-Z0-9]/', '_', $supplier->name) . '_' . date('Ymd_His') . '.pdf';
            $pdfContent = $pdf->output();

            // ========== UPDATE STATUS ==========
            $now = now();
            foreach ($badProducts as $item) {
                $item->update([
                    'status' => 'reported',
                    'reported_at' => $now,
                    'reported_to_supplier' => true,
                ]);
            }

            $this->logger->info('Export PDF dan update status barang rusak', [
                'supplier_id' => $supplierId,
                'supplier_name' => $supplier->name,
                'total_items' => $badProducts->count(),
                'admin_id' => $request->user()->id ?? null
            ]);

            // Return file PDF
            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'X-Reported-Count' => $badProducts->count(),
                'X-Total-Loss' => $totalLoss,
            ]);

        } catch (\Exception $e) {
            Log::error('Export PDF error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat export PDF: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * Preview kalkulasi sebelum submit
     */
    public function previewCalculation(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
                'unit' => 'required|string|max:50',
            ]);
            
            $product = Product::findOrFail($request->product_id);
            
            $calculation = BadProductCalculator::getCalculationDetail(
                $product,
                $request->quantity,
                $request->unit
            );
            
            return $this->success([
                'calculation' => $calculation,
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'unit' => $product->unit,
                    'purchase_price' => $product->purchase_price,
                    'selling_price' => $product->selling_price,
                ],
            ], 'Preview kalkulasi berhasil', 200);
            
        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * POST /api/admin/bad-products/{id}/compensate-cash
     */
    public function compensateCash($id, Request $request)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'payable_id' => 'nullable|exists:payables,id',
                'amount' => 'required|numeric|min:1',
                'notes' => 'nullable|string|max:500',
                'tanggal_kompensasi' => 'nullable|date|before_or_equal:today',
            ]);

            // 1. Lock BadProduct
            $badProduct = \App\Models\BadProduct::with('product')->where('id', $id)->lockForUpdate()->firstOrFail();
            
            // Cek apakah sudah selesai
            if ($badProduct->status_kompensasi === 'selesai') {
                DB::rollBack();
                return $this->error('Kompensasi untuk barang rusak ini sudah selesai / lunas', null, 400);
            }

            // Validasi manual tanggal kompensasi vs incident_date
            $tanggalKompensasi = $request->filled('tanggal_kompensasi') ? \Carbon\Carbon::parse($request->tanggal_kompensasi)->startOfDay() : now()->startOfDay();
            if ($badProduct->incident_date && $tanggalKompensasi->lt($badProduct->incident_date->startOfDay())) {
                DB::rollBack();
                return $this->error('Tanggal kompensasi tidak boleh lebih awal dari tanggal kejadian (' . $badProduct->incident_date->format('Y-m-d') . ')', null, 400);
            }

            // 2. Hitung Sisa Nilai yang belum terkompensasi
            $state = \App\Models\BadProduct::calculateCompensationState($badProduct);
            $sisaNilai = $state['sisa_nilai'];
            
            if ($request->amount > $sisaNilai) {
                DB::rollBack();
                return $this->error('Jumlah kompensasi ('.$request->amount.') melebihi sisa nilai kerugian ('.$sisaNilai.')', null, 400);
            }

            $payable = null;
            // 3. Eksekusi Potong Hutang JIKA payable_id ADA
            if ($request->filled('payable_id')) {
                $payable = \App\Models\Payable::where('id', $request->payable_id)->lockForUpdate()->firstOrFail();

                // Validasi kepemilikan supplier (seperti instruksi)
                if ($payable->supplier_id !== $badProduct->product->supplier_id) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'payable_id' => ['Hutang ini bukan milik supplier terkait barang rusak ini.']
                    ]);
                }

                // Validasi remaining debt
                if ($request->amount > $payable->remaining_debt) {
                    DB::rollBack();
                    return $this->error('Jumlah kompensasi melebihi sisa hutang di payable ini', null, 400);
                }

                // Eksekusi Potong Hutang (Re-use logic PayableController)
                $payable->paid_amount += $request->amount;
                $payable->remaining_debt -= $request->amount;
                $payable->status = $payable->remaining_debt <= 0 ? 'paid' : 'partial';
                $payable->save();
            }

            // 4. Eksekusi Update BadProduct
            $badProduct->compensated_value += $request->amount;
            
            // Hitung ulang status setelah value ditambahkan
            $newState = \App\Models\BadProduct::calculateCompensationState($badProduct);
            $badProduct->status_kompensasi = $newState['status'];
            
            // Catat history dalam format JSON via helper
            $metode = $request->filled('payable_id') ? 'potong_hutang' : 'tunai_langsung';
            
            $badProduct->appendKompensasiHistory([
                'tanggal' => $tanggalKompensasi->format('Y-m-d'),
                'jenis' => 'uang',
                'nominal' => (float)$request->amount,
                'jumlah' => null,
                'unit' => null,
                'metode' => $metode,
                'catatan' => $request->notes,
                'image_url' => null
            ]);
                
            $badProduct->tanggal_kompensasi = $tanggalKompensasi;
            $badProduct->save();

            DB::commit();

            return $this->success([
                'bad_product' => $badProduct,
                'payable' => $payable
            ], 'Kompensasi uang berhasil dicatat', 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors(), 'Data kompensasi tidak valid');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Compensate cash error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memproses kompensasi', null, 500);
        }
    }
}