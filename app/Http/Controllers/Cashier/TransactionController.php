<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\Setting;
use App\Traits\ApiResponseTrait;
use App\Services\ProfitCalculatorService;
use App\Services\MidtransService;
use App\Helpers\CloudinaryHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class TransactionController extends Controller
{
    use ApiResponseTrait;

    protected $profitService;
    protected $midtransService;

    public function __construct(ProfitCalculatorService $profitService, MidtransService $midtransService) {
        $this->profitService = $profitService;
        $this->midtransService = $midtransService;
    }

    /**
     * Store a new transaction
     * POST /api/cashier/transactions
     *
     * Metode pembayaran:
     * - cash (Tunai)
     * - transfer (Transfer Bank)
     * - qris (QRIS - scan gambar & input manual)
     * - midtrans_qris (QRIS Midtrans - nominal otomatis terkunci)
     * - receivable (Piutang/Hutang Pelanggan)
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            // Get enabled payment methods from settings
            $enabledMethods = [];
            if (Setting::getBool('payment_method_cash', true)) $enabledMethods[] = 'cash';
            if (Setting::getBool('payment_method_transfer', true)) $enabledMethods[] = 'transfer';
            if (Setting::getBool('payment_method_qris', true)) {
                $enabledMethods[] = 'qris';
                $enabledMethods[] = 'qris_statis';
            }
            if (Setting::getBool('payment_method_midtrans_qris', true)) $enabledMethods[] = 'midtrans_qris';
            if (Setting::getBool('payment_method_receivable', true)) $enabledMethods[] = 'receivable';

            $request->validate([
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                // Dynamic payment methods based on settings
                'payment_method' => 'required|in:' . implode(',', $enabledMethods),
                'paid_amount' => 'required_if:payment_method,cash,transfer|numeric|min:0',
                // Customer ID untuk piutang (wajib jika metode kredit)
                'customer_id' => 'required_if:payment_method,receivable|integer|exists:customers,id',
                'bukti_pembayaran' => 'required_if:payment_method,qris,qris_statis,transfer|image|max:2048',
                
                // Tambahan validasi FITUR 1
                'customer_name' => 'nullable|string|max:255',
                'customer_phone' => 'nullable|string|max:15',
            ]);

            // Merekam customer_id asli SEBELUM merge & validasi paling awal (Fitur 2 - Bagian C)
            $customerId = null;
            if ($request->payment_method === 'receivable') {
                $customerId = $request->customer_id;

                $customerForCredit = Customer::find($customerId);
                if (!$customerForCredit || $customerForCredit->member_status !== 'member') {
                    DB::rollBack();
                    return $this->error('Pelanggan ini belum terdaftar sebagai member, tidak bisa melakukan transaksi kredit/piutang.', null, 403);
                }
            }
            
            $totalAmount = 0;
            $itemsData = [];
            
            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);
                
                if ($product->stock < $item['quantity']) {
                    DB::rollBack();
                    return $this->error("Stok {$product->name} tidak mencukupi. Stok tersedia: {$product->stock}", null, 400);
                }
                
                $subtotal = $product->selling_price * $item['quantity'];
                $totalAmount += $subtotal;
                
                $itemsData[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'price' => $product->selling_price,
                    'purchase_price' => $product->purchase_price,
                    'subtotal' => $subtotal,
                ];
            }
            
            $paidAmount = $request->paid_amount ?? 0;
            $changeDue = 0;
            $paymentStatus = 'paid';
            $dueDate = null;
            $dpPaymentMethod = null;

            // Validasi custom untuk DP Piutang
            if ($request->payment_method === 'receivable') {
                if ($paidAmount > $totalAmount) {
                    DB::rollBack();
                    return $this->error('Jumlah DP (paid_amount) tidak boleh melebihi total belanja', null, 400);
                }

                if ($paidAmount > 0) {
                    if (!in_array($request->dp_payment_method, ['cash', 'transfer', 'qris_statis'])) {
                        DB::rollBack();
                        return $this->error('dp_payment_method wajib diisi (cash, transfer, qris_statis) jika paid_amount > 0', null, 422);
                    }
                    if (in_array($request->dp_payment_method, ['transfer', 'qris_statis']) && !$request->hasFile('bukti_pembayaran')) {
                        DB::rollBack();
                        return $this->error('Bukti pembayaran wajib diunggah untuk DP metode Transfer dan QRIS Statis.', null, 422);
                    }
                    $dpPaymentMethod = $request->dp_payment_method;
                }
            }

            // Logika per metode pembayaran
            if ($request->payment_method === 'receivable') {
                if ($paidAmount == $totalAmount) {
                    // Jika lunas seketika, ubah metode ke DP method dan status paid
                    $request->merge(['payment_method' => $dpPaymentMethod]);
                    $changeDue = 0;
                    // Lanjut diproses sebagai tunai lunas, abaikan tabel Receivable
                } else if ($paidAmount > 0) {
                    // DP sebagian
                    $paymentStatus = 'partial';
                    $dueDate = now()->addDays(5);
                } else {
                    // Murni hutang, nol DP
                    $paymentStatus = 'unpaid';
                    $dueDate = now()->addDays(5);
                }
            } elseif ($request->payment_method === 'qris' || $request->payment_method === 'qris_statis') {
                $paymentStatus = 'pending';
                $paidAmount = 0;
                $changeDue = 0;
            } elseif ($request->payment_method === 'midtrans_qris') {
                $paymentStatus = 'pending';
                $paidAmount = 0;
            } elseif ($paidAmount < $totalAmount) {
                DB::rollBack();
                return $this->error('Jumlah pembayaran kurang dari total belanja', null, 400);
            } else {
                $changeDue = $paidAmount - $totalAmount;
            }
            
            // Simpan bukti pembayaran jika ada
            $buktiPembayaranPath = null;
            if ($request->hasFile('bukti_pembayaran')) {
                $buktiPembayaranPath = CloudinaryHelper::upload($request->file('bukti_pembayaran'), 'bukti-pembayaran');
            }
            
            // Hitung Fee (Snapshot) berdasarkan metode pembayaran
            $feePercentage = 0;
            if (in_array($request->payment_method, ['qris', 'qris_statis', 'qris_biasa'])) {
                $feePercentage = (float) Setting::getValue('qris_fee_percentage', '0.7');
            } elseif ($request->payment_method === 'midtrans_qris') {
                $feePercentage = (float) Setting::getValue('midtrans_fee_percentage', '1.5');
            }
            $feeAmount = $totalAmount * ($feePercentage / 100);

            // Logika pencarian/pembuatan customer otomatis Fitur 1
            if ($customerId === null && empty($request->customer_phone) && $request->filled('customer_name')) {
                $customerName = trim($request->customer_name);
                $normalizedName = strtolower($customerName);
                
                $lock = \Illuminate\Support\Facades\Cache::lock('customer_name_lock_' . md5($normalizedName), 5);
                
                try {
                    $lock->block(5);
                    
                    $candidates = Customer::whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
                        ->whereNull('phone')
                        ->lockForUpdate()
                        ->get();
                        
                    if ($candidates->count() === 1) {
                        $customerId = $candidates->first()->id;
                    } elseif ($candidates->count() === 0) {
                        $customer = Customer::create([
                            'name' => $customerName,
                            'phone' => null,
                            'member_status' => 'umum',
                            'is_ambiguous' => false
                        ]);
                        $customerId = $customer->id;
                    } else {
                        // >= 2 collision
                        $customer = Customer::create([
                            'name' => $customerName,
                            'phone' => null,
                            'member_status' => 'umum',
                            'is_ambiguous' => true
                        ]);
                        $customerId = $customer->id;
                        
                        Customer::whereIn('id', $candidates->pluck('id'))
                            ->where('is_ambiguous', false)
                            ->update(['is_ambiguous' => true]);
                    }
                    
                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                    DB::rollBack();
                    return $this->error('Sistem sedang memproses transaksi lain dengan nama pelanggan yang sama. Silakan coba lagi dalam beberapa detik.', null, 409);
                } finally {
                    $lock?->release();
                }
            } elseif ($customerId === null && $request->filled('customer_phone')) {
                $phone = $request->customer_phone;
                
                $customer = Customer::where('phone', $phone)->first();
                if ($customer) {
                    $customerId = $customer->id;
                } else {
                    try {
                        $customerName = $request->customer_name ?: 'Pelanggan Umum';
                        
                        $customer = Customer::create([
                            'name' => $customerName,
                            'phone' => $phone,
                            'member_status' => 'umum',
                            'is_ambiguous' => false
                        ]);
                        $customerId = $customer->id;
                    } catch (\Illuminate\Database\QueryException $e) {
                        if (
                            in_array($e->getCode(), ['23000', '23505']) 
                            || str_contains($e->getMessage(), '1062')
                            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
                            || str_contains($e->getMessage(), 'duplicate key value violates unique constraint')
                        ) {
                            $customer = Customer::where('phone', $phone)->first();
                            if ($customer) {
                                $customerId = $customer->id;
                            } else {
                                throw $e;
                            }
                        } else {
                            throw $e;
                        }
                    }
                }
            }

            // 1. Buat data transaksi utama
            $transaction = Transaction::create([
                'cashier_id' => $request->user()->id,
                'customer_id' => $customerId,
                'payment_method' => $request->payment_method,
                'dp_payment_method' => $dpPaymentMethod,
                'payment_status' => $paymentStatus,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'change_due' => $changeDue,
                'payment_fee_percentage' => $feePercentage,
                'payment_fee_amount' => $feeAmount,
                'due_date' => $dueDate,
                'tx_date' => now('Asia/Jakarta')->toDateString(),
                'bukti_pembayaran' => $buktiPembayaranPath,
            ]);
            
            // 2. Simpan detail item & potong stok barang
            // STOK DIKURANGI HANYA JIKA:
            // - payment_method != 'midtrans_qris' (langsung paid / bukan tunggu payment)
            // - ATAU payment_method == 'receivable' (kredit - stok keluar walau belum lunas)
            // Untuk midtrans_qris, stok dikurangi NANTI SAAT WEBHOOK/KONFIRMASI paid
            $shouldDeductStock = $request->payment_method !== 'midtrans_qris';

            foreach ($itemsData as $item) {
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $item['product']->id,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'purchase_price' => $item['purchase_price'],
                    'subtotal' => $item['subtotal'],
                ]);

                // Kurangi stok HANYA jika bukan midtrans_qris
                if ($shouldDeductStock) {
                    $item['product']->decreaseStock($item['quantity']);

                    \App\Models\Stock::create([
                        'product_id' => $item['product']->id,
                        'type' => 'out',
                        'quantity' => $item['quantity'],
                        'description' => 'Penjualan - Invoice: ' . $transaction->invoice_number,
                        'user_id' => $request->user()->id,
                    ]);
                }
            }

            // Tandai stock_deducted jika sudah dikurangi
            if ($shouldDeductStock) {
                $transaction->update(['stock_deducted' => true]);
            }
            
            // 3. Hitung & simpan profit toko
            $this->profitService->calculateAndSaveProfit($transaction);
            
            $receivable = null;

            // 4. Pencatatan Piutang (tanpa Midtrans)
            // (Catatan: Jika lunas di awal, payment_method sudah berubah menjadi tunai/transfer, jadi ini tidak dieksekusi)
            if ($request->payment_method === 'receivable') {
                $customer = Customer::findOrFail($request->customer_id);

                $receivable = Receivable::create([
                    'transaction_id' => $transaction->id,
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->phone,
                    'customer_address' => $customer->address,
                    'total_debt' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'remaining_debt' => $totalAmount - $paidAmount,
                    'due_date' => $dueDate,
                    'status' => $paymentStatus,
                ]);

                // Jika ada DP, langsung buat histori pembayarannya
                if ($paidAmount > 0) {
                    \App\Models\ReceivablePayment::create([
                        'transaction_id' => $transaction->id,
                        'amount_paid' => $paidAmount,
                        'payment_channel' => strtoupper($dpPaymentMethod),
                        'paid_at' => now(),
                        'payment_date' => now()->toDateString(),
                        'bukti_pembayaran' => $buktiPembayaranPath,
                        'cashier_id' => $request->user()->id,
                    ]);
                }
            }

            // QRIS Statis - tidak perlu URL gambar (QR fisik sudah tertempel di kasir)
            // Tidak ada処理 khusus, cukup catat metode "qris" saja

            // Midtrans QRIS - generate QR payment
            if ($request->payment_method === 'midtrans_qris') {
                $midtransData = new \stdClass();
                $midtransData->total_amount = $totalAmount;
                $midtransData->invoice_number = $transaction->invoice_number;
                $midtransData->id = $transaction->id;

                $midtransResult = $this->midtransService->createQrisPayment($midtransData, 'Pelanggan');

                if (!$midtransResult['success']) {
                    DB::rollBack();
                    return $this->error($midtransResult['message'], null, 500);
                }

                $transaction->midtrans_order_id = $midtransResult['order_id'];
                $transaction->midtrans_qr_url = $midtransResult['qr_url'];
                $transaction->save();
            }

            DB::commit();

            // Clear products cache after transaction (stok berubah)
            // Clear all page variants (1-10) for all categories
            foreach (range(1, 10) as $page) {
                Cache::forget("cashier_products_all_page_{$page}");
                Cache::forget("cashier_products_egg_page_{$page}");
                Cache::forget("cashier_products_rice_page_{$page}");
            }

            // Logging
            \Illuminate\Support\Facades\Log::info('Transaksi penjualan berhasil dicatat', [
                'transaction_id' => $transaction->id,
                'invoice_number' => $transaction->invoice_number,
                'total_amount' => $totalAmount,
                'payment_method' => $request->payment_method,
            ]);
            
            // 5. Build Response Data JSON untuk Frontend Flutter
            $responseData = [
                'transaction' => $transaction->load('details.product'),
                'invoice_number' => $transaction->invoice_number,
                'total_amount' => $totalAmount,
                'total_amount_formatted' => 'Rp ' . number_format($totalAmount, 0, ',', '.'),
                'change_due' => $changeDue,
                'change_due_formatted' => 'Rp ' . number_format($changeDue, 0, ',', '.'),
            ];

            // Response berdasarkan metode pembayaran
            if ($request->payment_method === 'receivable') {
                $responseData['receivable'] = $receivable;
                $responseData['due_date'] = Carbon::parse($dueDate)->format('d/m/Y');
                $responseData['message'] = 'Piutang berhasil dicatat. Jatuh tempo 5 hari.';

            } elseif ($request->payment_method === 'qris') {
                // QRIS - tampilkan gambar untuk di-scan customer
                $responseData['qr_url'] = $transaction->midtrans_qr_url;
                $responseData['message'] = 'Tunjukkan QRIS kepada pelanggan untuk discan. Nominal input manual oleh pelanggan.';
            } elseif ($request->payment_method === 'midtrans_qris') {
                // Midtrans QRIS - nominal otomatis terkunci
                $responseData['qr_url'] = $transaction->midtrans_qr_url;
                $responseData['order_id'] = $transaction->midtrans_order_id;
                $responseData['message'] = 'QRIS Midtrans berhasil dibuat. Nominal otomatis terkunci sesuai total belanja, silakan scan.';
            } else {
                $responseData['message'] = 'Transaksi berhasil diselesaikan.';
            }
            
            return $this->success($responseData, $responseData['message'], 201);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors(), 'Data transaksi tidak valid');
        } catch (\Exception $e) {
            DB::rollBack();

            // Report ke Sentry dan Telegram
            report($e);

            return $this->error('Terjadi kesalahan backend: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Get transaction history for current cashier (TODAY ONLY)
     * GET /api/cashier/transactions/history
     *
     * Columns: No. Nota, Nama Barang, Jumlah, Harga, Metode
     * Actions: Cetak Ulang only
     */
    public function history(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);

            // Filter: TODAY only (pakai whereDate dengan timezone Asia/Jakarta)
            $todayDate = now('Asia/Jakarta')->toDateString();

            $query = Transaction::with(['details.product'])
                ->where('cashier_id', $request->user()->id)
                ->whereDate('created_at', $todayDate)
                ->validSales();
                
            // Tambahkan filter payment_method jika ada
            if ($request->has('payment_method') && $request->payment_method !== 'all' && $request->payment_method !== '') {
                if ($request->payment_method === 'qris') {
                    $query->whereIn('payment_method', ['qris', 'qris_statis', 'qris_biasa']);
                } else {
                    $query->where('payment_method', $request->payment_method);
                }
            }

            $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

            $formattedTransactions = $transactions->getCollection()->map(function ($transaction) {
                // Build items for display (Nama Barang, Jumlah, Harga)
                $items = [];
                foreach ($transaction->details as $detail) {
                    $items[] = [
                        'product_name' => $detail->product->name,
                        'quantity' => $detail->quantity,
                        'unit' => $detail->product->unit,
                        'price' => (float) $detail->price,
                        'subtotal' => (float) $detail->subtotal,
                    ];
                }

                // Determine customer type based on payment method
                $customerType = 'Umum';
                if ($transaction->payment_method === 'receivable' && $transaction->receivable) {
                    $customerType = $transaction->receivable->customer_name;
                }

                // Status label: untuk semua metode
                $statusLabel = 'Lunas';
                if ($transaction->payment_method === 'receivable') {
                    $statusLabel = $transaction->payment_status === 'paid' ? 'Lunas' : 'Kredit';
                } elseif ($transaction->payment_method === 'midtrans_qris') {
                    $statusLabel = $transaction->payment_status === 'paid' ? 'Lunas' : 'Pending';
                }

                // payment_status: selalu ada untuk semua metode
                $paymentStatus = $transaction->payment_status;

                $remainingBalance = max(0, $transaction->total_amount - $transaction->paid_amount);

                return [
                    'id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'tanggal' => $transaction->created_at->format('d/m/Y'),
                    'waktu' => $transaction->created_at->format('H:i:s'),
                    'total_amount' => (float) $transaction->total_amount,
                    'total_amount_formatted' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.'),
                    'paid_amount' => (float) $transaction->paid_amount,
                    'paid_amount_formatted' => 'Rp ' . number_format($transaction->paid_amount, 0, ',', '.'),
                    'remaining_balance' => (float) $remainingBalance,
                    'remaining_balance_formatted' => 'Rp ' . number_format($remainingBalance, 0, ',', '.'),
                    'payment_method' => $transaction->payment_method,
                    'payment_method_label' => $this->getPaymentMethodText($transaction->payment_method),
                    'payment_status' => $paymentStatus,
                    'status_label' => $statusLabel,
                    'customer_type' => $customerType,
                    'items' => $items,
                    'items_count' => $transaction->details->count(),
                    'bukti_pembayaran_url' => $transaction->bukti_pembayaran_url,
                ];
            });

            $transactions->setCollection($formattedTransactions);

            // Summary for today
            $todaySummary = [
                'total_transactions' => Transaction::where('cashier_id', $request->user()->id)
                    ->whereDate('created_at', $todayDate)
                    ->validSales()
                    ->count(),
                'total_omzet' => (float) Transaction::where('cashier_id', $request->user()->id)
                    ->whereDate('created_at', $todayDate)
                    ->validSales()
                    ->sum('total_amount'),
                'total_kas_diterima' => (float) Transaction::where('cashier_id', $request->user()->id)
                    ->whereDate('created_at', $todayDate)
                    ->validSales()
                    ->sum(\Illuminate\Support\Facades\DB::raw('CASE WHEN paid_amount > total_amount THEN total_amount ELSE paid_amount END')),
                'paid_count' => Transaction::where('cashier_id', $request->user()->id)
                    ->whereDate('created_at', $todayDate)
                    ->where('payment_status', 'paid')
                    ->count(),
                'partial_count' => Transaction::where('cashier_id', $request->user()->id)
                    ->whereDate('created_at', $todayDate)
                    ->where('payment_status', 'partial')
                    ->count(),
                'unpaid_count' => Transaction::where('cashier_id', $request->user()->id)
                    ->whereDate('created_at', $todayDate)
                    ->where('payment_status', 'unpaid')
                    ->count(),
                'pending_count' => Transaction::where('cashier_id', $request->user()->id)
                    ->whereDate('created_at', $todayDate)
                    ->where('payment_status', 'pending')
                    ->count(),
                'piutang_count' => Transaction::where('cashier_id', $request->user()->id)
                    ->whereDate('created_at', $todayDate)
                    ->whereIn('payment_status', ['unpaid', 'partial'])
                    ->count(),
            ];

            return $this->success([
                'transactions' => $transactions,
                'summary' => $todaySummary,
            ], 'Riwayat transaksi hari ini berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan saat memuat riwayat transaksi', null, 500);
        }
    }

    /**
     * Get transaction struk (receipt)
     * GET /api/cashier/transactions/{id}/struk
     */
    public function getStruk($id, Request $request)
    {
        try {
            $transaction = Transaction::with(['details.product', 'cashier', 'receivable'])
                ->where('cashier_id', $request->user()->id)
                ->findOrFail($id);

            // Hitung change_amount berdasarkan metode pembayaran
            $changeAmount = 0;
            $paidAmount = (float) $transaction->paid_amount;

            if (in_array($transaction->payment_method, ['cash', 'transfer'])) {
                $changeAmount = max(0, $paidAmount - $transaction->total_amount);
            } elseif (in_array($transaction->payment_method, ['qris', 'midtrans_qris', 'qris_biasa', 'qris_statis'])) {
                $paidAmount = (float) $transaction->total_amount;
                $changeAmount = 0;
            } elseif ($transaction->payment_method === 'receivable') {
                // Biarkan $paidAmount mengambil dari $transaction->paid_amount (DP awal)
                $changeAmount = 0;
            }

            // Data customer untuk kredit
            $customerData = null;
            $dueDate = null;
            $remainingBalance = 0;

            if ($transaction->payment_method === 'receivable') {
                $remainingBalance = $transaction->receivable 
                    ? (float) $transaction->receivable->remaining_debt 
                    : (float) max(0, $transaction->total_amount - $transaction->paid_amount);

                if ($transaction->receivable) {
                    $customerData = [
                        'id' => $transaction->receivable->customer_id,
                        'name' => $transaction->receivable->customer_name,
                        'phone' => $transaction->receivable->customer_phone,
                    ];
                    $dueDate = $transaction->receivable->due_date
                        ? Carbon::parse($transaction->receivable->due_date)->format('d/m/Y')
                        : null;
                }
            }

            // Label metode bayar dinamis
            $paymentLabel = match($transaction->payment_method) {
                'cash' => 'Tunai',
                'transfer' => 'Transfer Bank',
                'qris', 'qris_statis', 'qris_biasa' => 'QRIS',
                'midtrans_qris' => 'QRIS Otomatis',
                'receivable' => $transaction->dp_payment_method ? 'Kredit (DP via ' . ucfirst($transaction->dp_payment_method) . ')' : 'Kredit',
                default => ucfirst(str_replace('_', ' ', $transaction->payment_method))
            };

            // Build items
            $items = $transaction->details->map(function ($detail) {
                return [
                    'product_name' => $detail->product->name,
                    'quantity' => $detail->quantity,
                    'unit' => $detail->product->unit,
                    'price' => (float) $detail->price,
                    'price_formatted' => 'Rp ' . number_format($detail->price, 0, ',', '.'),
                    'subtotal' => (float) $detail->subtotal,
                    'subtotal_formatted' => 'Rp ' . number_format($detail->subtotal, 0, ',', '.'),
                ];
            });

            $strukData = [
                'invoice_number' => $transaction->invoice_number,
                'created_at' => $transaction->created_at->toIso8601String(),
                'cashier_name' => $transaction->cashier->name,
                'payment_method' => $transaction->payment_method,
                'payment_method_label' => $paymentLabel,
                'payment_status' => $transaction->payment_status,
                'total_amount' => (float) $transaction->total_amount,
                'total_amount_formatted' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.'),
                'paid_amount' => $paidAmount,
                'paid_amount_formatted' => 'Rp ' . number_format($paidAmount, 0, ',', '.'),
                'change_amount' => $changeAmount,
                'change_amount_formatted' => 'Rp ' . number_format($changeAmount, 0, ',', '.'),
                'remaining_balance' => $remainingBalance,
                'remaining_balance_formatted' => 'Rp ' . number_format($remainingBalance, 0, ',', '.'),
                'customer' => $customerData,
                'due_date' => $dueDate,
                'items' => $items,
                'store' => [
                    'name' => 'Grosir Tiga Bersaudara',
                    'address' => 'Jl. Rimbo Data, Bandar Buat, Padang',
                    'phone' => '082181769006',
                ],
            ];

            // QR info untuk midtrans_qris
            if ($transaction->payment_method === 'midtrans_qris' && $transaction->midtrans_qr_url) {
                $strukData['qr_url'] = $transaction->midtrans_qr_url;
                $strukData['order_id'] = $transaction->midtrans_order_id;
            }

            \Illuminate\Support\Facades\Log::info('Struk transaksi dimuat', [
                'transaction_id' => $transaction->id,
                'cashier_id' => $request->user()->id
            ]);

            return $this->success($strukData, 'Struk berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan saat memuat struk', null, 500);
        }
    }

    /**
     * Get payment method text in Indonesian
     */
    private function getPaymentMethodText($method): string
    {
        return match($method) {
            'cash' => 'Tunai',
            'transfer' => 'Transfer Bank',
            'qris' => 'QRIS Statis',
            'qris_statis' => 'QRIS Statis',
            'midtrans_qris' => 'QRIS Midtrans',
            'receivable' => 'Piutang',
            default => $method,
        };
    }

    /**
     * Get payment status label in Indonesian
     */
    private function getPaymentStatusLabel($status): string
    {
        return match($status) {
            'paid' => 'Lunas',
            'partial' => 'Cicilan (DP 50%)',
            'pending' => 'Menunggu Pembayaran',
            'unpaid' => 'Belum Dibayar',
            default => $status,
        };
    }

    /**
     * Get transaction payment status for polling
     * GET /api/cashier/transactions/{id}/status
     *
     * Lightweight endpoint - only fetch 1 field from transactions table
     * Flutter polls every 3 seconds until status = "paid" or "expired"
     */
    public function getPaymentStatus($id, Request $request)
    {
        try {
            $transaction = Transaction::where('cashier_id', $request->user()->id)
                ->findOrFail($id);

            $paymentStatus = $transaction->payment_status;

            // Transform payment_status to frontend expected values
            $frontendStatus = match($paymentStatus) {
                'pending' => 'pending',
                'paid' => 'paid',
                'partial' => 'pending', // masih menunggu pelunasan
                'unpaid' => 'pending',
                'cancelled' => 'expired',
                'expired' => 'expired',
                'failed' => 'failed',
                default => 'pending',
            };

            $response = [
                'transaction_id' => $transaction->id,
                'payment_status' => $frontendStatus,
                'payment_method' => $transaction->payment_method,
                'order_id' => $transaction->midtrans_order_id,
                'paid_at' => $transaction->paid_at,
            ];

            return $this->success($response, 'Status pembayaran berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Transaksi tidak ditemukan', null, 404);
        }
    }

    /**
     * Konfirmasi pembayaran manual (untuk QRIS statis)
     * PATCH /api/cashier/transactions/{id}/confirm-payment
     */
    public function confirmPayment($id, Request $request)
    {
        try {
            $transaction = Transaction::where('cashier_id', $request->user()->id)->findOrFail($id);

            // Validasi: payment_method harus 'qris' (QRIS statis)
            if ($transaction->payment_method !== 'qris') {
                return $this->error('Hanya transaksi QRIS statis yang dapat dikonfirmasi manual', null, 400);
            }

            // Validasi status saat ini必须是 pending
            if ($transaction->payment_status !== 'pending') {
                return $this->error('Status pembayaran bukan pending. Status saat ini: ' . $transaction->payment_status, null, 400);
            }

            // Update status ke paid
            $transaction->update([
                'payment_status' => 'paid',
                'paid_amount' => $transaction->total_amount,
                'paid_at' => now(),
            ]);

            // KURANGI STOK JIKA BELUM PERNAH DIKURANGI
            // QRIS statis stok dikurangi saat transaksi dibuat (lihat store()),
            // tapi ini sebagai backup jikalau ada edge case
            if (!$transaction->stock_deducted) {
                $this->deductStock($transaction);

                // Tandai stock sudah dikurangi
                $transaction->update(['stock_deducted' => true]);
            }

            // Clear products cache after confirm payment (stok berubah)
            foreach (range(1, 10) as $page) {
                Cache::forget("cashier_products_all_page_{$page}");
                Cache::forget("cashier_products_egg_page_{$page}");
                Cache::forget("cashier_products_rice_page_{$page}");
            }

            return $this->success([
                'id' => $transaction->id,
                'invoice_number' => $transaction->invoice_number,
                'payment_status' => $transaction->payment_status,
                'paid_at' => $transaction->paid_at,
                'stock_deducted' => $transaction->stock_deducted,
            ], 'Pembayaran berhasil dikonfirmasi', 200);

        } catch (\Exception $e) {
            return $this->error('Transaksi tidak ditemukan', null, 404);
        }
    }

    /**
     * Cek status payment Midtrans langsung via API (Tanpa webhook)
     * GET /api/cashier/transactions/{id}/check-midtrans-status
     */
    public function checkMidtransStatus(int $id)
    {
        try {
            $cashier = auth()->user();

            $transaction = Transaction::where('id', $id)
                ->where('cashier_id', $cashier->id)
                ->first();

            if (!$transaction) {
                return $this->error('Transaksi tidak ditemukan', null, 404);
            }

            // Validasi: hanya untuk pembayaran midtrans_qris
            if ($transaction->payment_method !== 'midtrans_qris') {
                return $this->error('Hanya transaksi Midtrans QRIS yang dapat dicek statusnya', null, 400);
            }

            // Validasi:必须有 midtrans_order_id
            if (!$transaction->midtrans_order_id) {
                return $this->error('Transaksi ini tidak memiliki order ID Midtrans', null, 400);
            }

            // Langsung query ke Midtrans API
            $midtrans = app(MidtransService::class);
            $result = $midtrans->getTransactionStatus($transaction->midtrans_order_id);

            if (!$result['success']) {
                // Cek Kalau 404 - berarti transaksi tidak ada di Midtrans (belum dibuat / expired)
                if (isset($result['http_status']) && $result['http_status'] === 404) {
                    $transaction->update(['payment_status' => 'failed']);
                    // TIDAK Perlu kembalikan stok karena tidak pernah dikurangi untuk midtrans_qris

                    return $this->error(
                        'Transaksi tidak ditemukan di Midtrans (mungkin expired atau belum dibuat). Stok tidak dikembalikan karena belum dikurangi.',
                        ['invoice_number' => $transaction->invoice_number],
                        404
                    );
                }

                return $this->error($result['message'] ?? 'Gagal terhubung ke Midtrans', null, 500);
            }

            $midtransStatus = $result['status'];

            // Update lokal jika status Midtrans adalah settlement/capture (PAID)
            if (in_array($midtransStatus, ['settlement', 'capture'])) {
                $transaction->update([
                    'payment_status' => 'paid',
                    'paid_amount' => $transaction->total_amount,
                    'paid_at' => now(),
                ]);

                // KURANGI STOK JIKA BELUM PERNAH DIKURANGI (cek flag stock_deducted)
                // Ini mencegah double-kurang jika webhook dan polling berjalan bersamaan
                if (!$transaction->stock_deducted) {
                    $this->deductStock($transaction);

                    // Tandai stock sudah dikurangi
                    $transaction->update(['stock_deducted' => true]);
                }

                // Clear products cache after Midtrans payment verified (stok berubah)
                foreach (range(1, 10) as $page) {
                    Cache::forget("cashier_products_all_page_{$page}");
                    Cache::forget("cashier_products_egg_page_{$page}");
                    Cache::forget("cashier_products_rice_page_{$page}");
                }

                return $this->success([
                    'id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'midtrans_transaction_status' => $midtransStatus,
                    'payment_status' => 'paid',
                    'paid_at' => $transaction->paid_at,
                ], 'Pembayaran berhasil diverifikasi dari Midtrans', 200);
            }

            // Jika status masih pending
            if ($midtransStatus === 'pending') {
                return $this->success([
                    'id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'midtrans_transaction_status' => $midtransStatus,
                    'payment_status' => $transaction->payment_status,
                ], 'Pembayaran masih menunggu dari pelanggan', 200);
            }

            // Jika expired/cancel/failure
            if (in_array($midtransStatus, ['expire', 'cancel', 'deny', 'failure'])) {
                $transaction->update([
                    'payment_status' => 'failed',
                ]);

                // TIDAK Perlu kembalikan stok karena tidak pernah dikurangi untuk midtrans_qris

                return $this->success([
                    'id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'midtrans_transaction_status' => $midtransStatus,
                    'payment_status' => 'failed',
                ], 'Pembayaran gagal atau kedaluwarsa', 200);
            }

            // Default: return current status
            return $this->success([
                'id' => $transaction->id,
                'invoice_number' => $transaction->invoice_number,
                'midtrans_transaction_status' => $midtransStatus,
                'payment_status' => $transaction->payment_status,
            ], 'Status check completed', 200);

        } catch (\Exception $e) {
            \Log::error('checkMidtransStatus error: ' . $e->getMessage(), [
                'transaction_id' => $id,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Throw ulang agar masuk ke Handler & Telegram/Sentry
            throw $e;
        }
    }

    /**
     * Helper: Kembalikan stok saat payment gagal
     */
    private function reverseStockOnFailure(Transaction $transaction): void
    {
        $transaction->load('details.product');

        foreach ($transaction->details as $detail) {
            $product = $detail->product;
            if ($product) {
                $product->increment('stock', $detail->quantity);
            }
        }
    }

    /**
     * Helper: Kurangi stok untuk transaksi (saat payment berhasil)
     */
    private function deductStock(Transaction $transaction): void
    {
        $transaction->load('details.product');

        foreach ($transaction->details as $detail) {
            $product = $detail->product;
            if ($product) {
                $product->decreaseStock($detail->quantity);

                \App\Models\Stock::create([
                    'product_id' => $product->id,
                    'type' => 'out',
                    'quantity' => $detail->quantity,
                    'description' => 'Penjualan (Midtrans Lunas) - Invoice: ' . $transaction->invoice_number,
                    'user_id' => $transaction->cashier_id,
                ]);
            }
        }
    }
}