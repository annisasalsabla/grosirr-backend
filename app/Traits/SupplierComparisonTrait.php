<?php

namespace App\Traits;

use Illuminate\Http\Request;
use App\Models\BadProduct;
use App\Models\Supplier;
use Carbon\Carbon;

trait SupplierComparisonTrait
{
    // Use DateRangeHelper so BOTH Admin and Owner controllers get access
    // to getFlutterWeeklyRange automatically without modifying the controllers.
    use DateRangeHelper;

    /**
     * Endpoint untuk membandingkan statistik barang rusak Telur vs Beras
     * Route: GET /api/{role}/bad-products/supplier-comparison
     */
    public function getSupplierComparison(Request $request)
    {
        // 1. Base Query untuk Telur dan Beras
        $queryEgg = BadProduct::whereHas('product', function($q) {
            $q->where('category', 'egg');
        });
        
        $queryRice = BadProduct::whereHas('product', function($q) {
            $q->where('category', 'rice');
        });

        // 2. Terapkan HANYA filter periode (Abaikan filter status agar semua terhitung)
        $this->applyPeriodOnlyFilter($queryEgg, $request);
        $this->applyPeriodOnlyFilter($queryRice, $request);

        // 3. Eksekusi
        $eggProducts = $queryEgg->get();
        $riceProducts = $queryRice->get();

        // 4. Kalkulasi Telur
        $eggSupplierName = $this->getSupplierNameByCategory('egg');
        $eggLaporan = $eggProducts->count();
        $eggKerugian = (float) $eggProducts->sum('loss_amount');

        // 5. Kalkulasi Beras
        $riceSupplierName = $this->getSupplierNameByCategory('rice');
        $riceLaporan = $riceProducts->count();
        $riceKerugian = (float) $riceProducts->sum('loss_amount');

        // Set locale to id for Indonesian month names
        Carbon::setLocale('id');

        return response()->json([
            'success' => true,
            'data' => [
                'period_label' => $this->getPeriodLabel($request),
                'telur' => [
                    'supplier_name' => $eggSupplierName,
                    'total_laporan' => $eggLaporan,
                    'total_kerugian' => $eggKerugian,
                    'total_kerugian_formatted' => 'Rp ' . number_format($eggKerugian, 0, ',', '.')
                ],
                'beras' => [
                    'supplier_name' => $riceSupplierName,
                    'total_laporan' => $riceLaporan,
                    'total_kerugian' => $riceKerugian,
                    'total_kerugian_formatted' => 'Rp ' . number_format($riceKerugian, 0, ',', '.')
                ]
            ]
        ], 200);
    }

    /**
     * Reusable period filter logic yang IDENTIK dengan BadProductController.
     * Menggunakan field 'tanggal_kejadian'.
     */
    private function applyPeriodOnlyFilter($query, Request $request)
    {
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
                        $query->whereBetween('tanggal_kejadian', [$range['start'], $range['end']]);
                    } else {
                        $baseDate = Carbon::parse($request->input('date', now()->toDateString()));
                        $query->whereBetween('tanggal_kejadian', [
                            $baseDate->copy()->startOfWeek()->toDateString(), 
                            $baseDate->copy()->endOfWeek()->toDateString()
                        ]);
                    }
                    break;
                case 'monthly':
                    $month = $request->input('month', now()->month);
                    $year = $request->input('year', now()->year);
                    $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
                    $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
                    $query->whereBetween('tanggal_kejadian', [$start, $end]);
                    break;
                case 'custom':
                    if ($request->has('start_date') && $request->has('end_date')) {
                        $query->whereBetween('tanggal_kejadian', [$request->start_date, $request->end_date]);
                    }
                    break;
            }
        }
    }

    /**
     * Helper untuk menarik nama supplier jika tidak ada transaksi di periode tersebut
     */
    private function getSupplierNameByCategory($category)
    {
        $supplier = Supplier::whereHas('products', function($q) use ($category) {
            $q->where('category', $category);
        })->first();
        
        return $supplier ? $supplier->name : null;
    }

    /**
     * Helper untuk merender Label Periode ke Flutter
     */
    private function getPeriodLabel(Request $request)
    {
        if (!$request->has('period')) return 'Semua Waktu';
        
        // Ensure Indonesian locale
        Carbon::setLocale('id');

        switch ($request->period) {
            case 'daily':
                $date = Carbon::parse($request->input('date', now()->toDateString()));
                return $date->translatedFormat('d F Y');
                
            case 'weekly':
                $week  = $request->input('week');
                $month = $request->input('month', now()->month);
                $year  = $request->input('year', now()->year);

                if ($week !== null) {
                    $range = $this->getFlutterWeeklyRange($week, $month, $year);
                    $startDate = Carbon::parse($range['start']);
                    $endDate   = Carbon::parse($range['end']);
                } else {
                    $baseDate  = Carbon::parse($request->input('date', now()->toDateString()));
                    $startDate = $baseDate->copy()->startOfWeek();
                    $endDate   = $baseDate->copy()->endOfWeek();
                }
                
                // Format: "Minggu 4, Juli 2026 (18 Jul - 24 Jul 2026)" atau sekedar range
                if ($week !== null) {
                    $monthName = Carbon::createFromDate($year, $month, 1)->translatedFormat('F');
                    return "Minggu {$week}, {$monthName} {$year} (" . $startDate->translatedFormat('d M') . ' - ' . $endDate->translatedFormat('d M Y') . ")";
                } else {
                    return $startDate->translatedFormat('d M') . ' - ' . $endDate->translatedFormat('d M Y');
                }
                
            case 'monthly':
                $month = $request->input('month', now()->month);
                $year = $request->input('year', now()->year);
                return Carbon::createFromDate($year, $month, 1)->translatedFormat('F Y');
                
            case 'custom':
                if ($request->has('start_date') && $request->has('end_date')) {
                    $start = Carbon::parse($request->start_date);
                    $end = Carbon::parse($request->end_date);
                    return $start->translatedFormat('d M Y') . ' - ' . $end->translatedFormat('d M Y');
                }
                return 'Kustom';
                
            default:
                return 'Semua Waktu';
        }
    }
}
