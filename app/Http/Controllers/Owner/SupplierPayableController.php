<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Payable;
use App\Models\Supplier;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

/**
 * Pemantauan Hutang ke Supplier - Real-time untuk Owner
 * Bukan laporan/report, hanya lihat kondisi saat ini
 */
class SupplierPayableController extends Controller
{
    use ApiResponseTrait;

    /**
     * Pemantauan Hutang ke Supplier
     * GET /owner/payables
     *
     * Mengembalikan list supplier dan produk yang masih punya hutang
     * Note: Tabel payables tidak punya product_id, hutang per supplier
     */
    public function index(Request $request)
    {
        try {
            // Ambil semua payable yang belum lunas (unpaid + partial)
            $payables = Payable::whereIn('status', ['unpaid', 'partial'])
                ->with(['supplier'])
                ->orderBy('due_date', 'asc')
                ->get();

            // Group by supplier_id dulu untuk akumulasi hutang per supplier
            $groupedBySupplier = $payables->groupBy('supplier_id');

            // Format list: group by supplier_id
            $list = $groupedBySupplier->map(function ($supplierPayables) {
                $first = $supplierPayables->first();
                $supplier = $first->supplier;

                $totalDebt = $supplierPayables->sum('remaining_debt');
                $transactionCount = $supplierPayables->count();
                $earliestDueDate = $supplierPayables->sortBy('due_date')->first()->due_date;

                $uniqueStatuses = $supplierPayables->pluck('status')->unique();
                $hasUnpaid  = $uniqueStatuses->contains('unpaid');
                $hasPartial = $uniqueStatuses->contains('partial');

                if ($transactionCount === 1) {
                    $worstStatus  = $first->status;
                    $statusLabel  = $hasUnpaid ? 'Belum Lunas' : 'Sebagian Lunas';
                } elseif ($hasUnpaid && $hasPartial) {
                    $unpaidCount  = $supplierPayables->where('status', 'unpaid')->count();
                    $partialCount = $supplierPayables->where('status', 'partial')->count();
                    $worstStatus  = 'mixed';
                    $statusLabel  = "Campuran ({$unpaidCount} belum lunas, {$partialCount} sebagian)";
                } elseif ($hasUnpaid) {
                    $worstStatus  = 'unpaid';
                    $statusLabel  = 'Belum Lunas';
                } else {
                    $worstStatus  = 'partial';
                    $statusLabel  = 'Sebagian Lunas';
                }

                $categories = $supplier?->products->pluck('category')->unique()->toArray() ?? [];
                $productNames = $supplier?->products->pluck('name')->unique()->implode(', ') ?? 'Various';

                return [
                    'supplier_name'          => $supplier?->name ?? 'Unknown',
                    'product_name'           => $productNames,
                    'category'               => implode(', ', $categories),
                    'categories'             => $categories,
                    'total_hutang'           => (float) $totalDebt,
                    'total_hutang_formatted' => 'Rp ' . number_format($totalDebt, 0, ',', '.'),
                    'transaction_count'      => $transactionCount,
                    'earliest_due_date'      => $earliestDueDate?->format('d/m/Y'),
                    'status'                 => $worstStatus,
                    'status_label'           => $statusLabel,
                ];
            })
            // Sort berdasarkan earliest_due_date
            ->sortBy(fn($item) => $item['earliest_due_date'])
            // Filter HANYA supplier dengan sisa hutang > 0
            ->filter(fn($item) => $item['total_hutang'] > 0)
            ->values();

            // Total dari list yang sudah difilter
            $totalPayable = $list->sum('total_hutang');
            $uniqueSupplierIds = $list->count();

            $eggPayable = $list->filter(fn($c) => in_array('egg', $c['categories']))->sum('total_hutang');
            $ricePayable = $list->filter(fn($c) => in_array('rice', $c['categories']))->sum('total_hutang');
            
            $eggSupplierCount = $list->filter(fn($c) => in_array('egg', $c['categories']))->count();
            $riceSupplierCount = $list->filter(fn($c) => in_array('rice', $c['categories']))->count();

            return $this->success([
                'summary' => [
                    'total_hutang' => (float) $totalPayable,
                    'total_hutang_formatted' => 'Rp ' . number_format($totalPayable, 0, ',', '.'),
                    'total_supplier' => $uniqueSupplierIds,
                ],
                'by_category' => [
                    'all' => [
                        'name' => 'Semua Produk',
                        'total_hutang' => (float) $totalPayable,
                        'total_hutang_formatted' => 'Rp ' . number_format($totalPayable, 0, ',', '.'),
                        'total_supplier' => $uniqueSupplierIds,
                    ],
                    'egg' => [
                        'name' => 'Telur',
                        'total_hutang' => (int) $eggPayable,
                        'total_hutang_formatted' => 'Rp ' . number_format($eggPayable, 0, ',', '.'),
                        'total_supplier' => $eggSupplierCount,
                    ],
                    'rice' => [
                        'name' => 'Beras',
                        'total_hutang' => (int) $ricePayable,
                        'total_hutang_formatted' => 'Rp ' . number_format($ricePayable, 0, ',', '.'),
                        'total_supplier' => $riceSupplierCount,
                    ],
                ],
                'suppliers' => $list
            ], 'Pemantauan hutang supplier berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }
}