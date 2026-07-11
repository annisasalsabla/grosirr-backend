<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Receivable;
use App\Models\TransactionDetail;
use App\Models\Product;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pemantauan Piutang Pelanggan - Real-time untuk Owner
 * Bukan laporan/report, hanya lihat kondisi saat ini
 */
class CustomerReceivableController extends Controller
{
    use ApiResponseTrait;

    /**
     * Pemantauan Piutang Pelanggan
     * GET /owner/receivables
     *
     * Mengembalikan list siapa saja yang masih punya piutang
     */
    public function index(Request $request)
    {
        try {
            // Ambil semua receivable yang belum lunas (unpaid + partial)
            $receivables = Receivable::whereIn('status', ['unpaid', 'partial'])
                ->with(['transaction'])
                ->orderBy('due_date', 'asc')
                ->get();

            // Total piutang keseluruhan (semua yang belum lunas)
            $totalReceivable = $receivables->sum('remaining_debt');

            // Hitung jumlah customer unik (dihitung setelah groupBy agar akurat)
            // Catatan: $uniqueCustomers sementara dihitung dari unique() dulu,
            // lalu di-overwrite dari $list->count() setelah groupBy selesai

            // Piutang berdasarkan kategori produk (via transaction_details -> products)
            $eggReceivable = 0;
            $riceReceivable = 0;

            foreach ($receivables as $receivable) {
                if ($receivable->transaction) {
                    $transactionId = $receivable->transaction->id;
                    $eggAmount = TransactionDetail::where('transaction_id', $transactionId)
                        ->whereHas('product', fn($q) => $q->where('category', 'egg'))
                        ->sum(DB::raw('price * quantity'));
                    $riceAmount = TransactionDetail::where('transaction_id', $transactionId)
                        ->whereHas('product', fn($q) => $q->where('category', 'rice'))
                        ->sum(DB::raw('price * quantity'));

                    // Proporsikan berdasarkan rasio remaining debt
                    $ratio = $receivable->remaining_debt / ($receivable->total_debt ?: 1);
                    $eggReceivable += $eggAmount * $ratio;
                    $riceReceivable += $riceAmount * $ratio;
                }
            }

            // Format list berdasarkan customer dan gabungkan total piutangnya
            $list = $receivables->groupBy('customer_name')->map(function ($items) {
                $first = $items->first();
                $totalPiutang = $items->sum('remaining_debt');
                $transactionCount = $items->count();

                // due_date = MIN (yang paling mendesak/segera jatuh tempo)
                $earliestDueDate = $items->sortBy('due_date')->first()->due_date;

                // Deteksi campuran status
                $uniqueStatuses = $items->pluck('status')->unique();
                $hasUnpaid  = $uniqueStatuses->contains('unpaid');
                $hasPartial = $uniqueStatuses->contains('partial');

                if ($transactionCount === 1) {
                    // Hanya 1 transaksi - tampilkan apa adanya
                    $worstStatus  = $first->status;
                    $statusLabel  = $hasUnpaid ? 'Belum Lunas' : 'Sebagian Lunas';
                } elseif ($hasUnpaid && $hasPartial) {
                    // Campuran unpaid + partial
                    $unpaidCount  = $items->where('status', 'unpaid')->count();
                    $partialCount = $items->where('status', 'partial')->count();
                    $worstStatus  = 'mixed';
                    $statusLabel  = "Campuran ({$unpaidCount} belum lunas, {$partialCount} sebagian)";
                } elseif ($hasUnpaid) {
                    $worstStatus  = 'unpaid';
                    $statusLabel  = 'Belum Lunas';
                } else {
                    $worstStatus  = 'partial';
                    $statusLabel  = 'Sebagian Lunas';
                }

                // Kumpulkan kategori produk dari semua transaksi customer ini
                // (digunakan Flutter untuk filter tombol Semua/Telur/Beras)
                $categories = $items->flatMap(function ($receivable) {
                    if (!$receivable->transaction) return [];
                    return \App\Models\TransactionDetail::where('transaction_id', $receivable->transaction->id)
                        ->join('products', 'transaction_details.product_id', '=', 'products.id')
                        ->pluck('products.category')
                        ->toArray();
                })->unique()->values()->toArray();

                return [
                    'customer_name'           => $first->customer_name,
                    'customer_phone'          => $first->customer_phone,
                    'total_piutang'           => (float) $totalPiutang,
                    'total_piutang_formatted' => 'Rp ' . number_format($totalPiutang, 0, ',', '.'),
                    'transaction_count'       => $transactionCount,
                    'earliest_due_date'       => $earliestDueDate?->format('d/m/Y'),
                    'categories'              => $categories, // ['rice'], ['egg'], atau ['egg', 'rice']
                    'status'                  => $worstStatus,
                    'status_label'            => $statusLabel,
                ];
            })
            // Sort hasil akhir berdasarkan earliest_due_date (paling mendesak duluan)
            ->sortBy(fn($item) => $item['earliest_due_date'])
            ->values();

            // Filter agar HANYA customer yang benar-benar punya sisa hutang > 0 yang dikirim
            $list = $list->filter(fn($item) => $item['total_piutang'] > 0)->values();

            // Total piutang keseluruhan (semua yang belum lunas) dari list final
            $totalReceivable = $list->sum('total_piutang');
            $uniqueCustomers = $list->count();

            // Piutang berdasarkan kategori produk dari list final
            $eggReceivable = $list->filter(fn($c) => in_array('egg', $c['categories']))->sum('total_piutang');
            $riceReceivable = $list->filter(fn($c) => in_array('rice', $c['categories']))->sum('total_piutang');

            return $this->success([
                'summary' => [
                    'total_piutang'           => (float) $totalReceivable,
                    'total_piutang_formatted' => 'Rp ' . number_format($totalReceivable, 0, ',', '.'),
                    'total_customer'          => $uniqueCustomers,
                ],
                'by_category' => [
                    'all' => [
                        'name' => 'Semua Produk',
                        'total_piutang' => (float) $totalReceivable,
                        'total_piutang_formatted' => 'Rp ' . number_format($totalReceivable, 0, ',', '.'),
                    ],
                    'egg' => [
                        'name' => 'Telur',
                        'total_piutang' => (float) $eggReceivable,
                        'total_piutang_formatted' => 'Rp ' . number_format($eggReceivable, 0, ',', '.'),
                    ],
                    'rice' => [
                        'name' => 'Beras',
                        'total_piutang' => (float) $riceReceivable,
                        'total_piutang_formatted' => 'Rp ' . number_format($riceReceivable, 0, ',', '.'),
                    ],
                ],
                'customers' => $list
            ], 'Pemantauan piutang pelanggan berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }
}