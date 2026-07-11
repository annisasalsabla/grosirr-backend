<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Profit;
use App\Traits\ApiResponseTrait;
use App\Services\ProfitCalculatorService;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfitReportController extends Controller
{
    use ApiResponseTrait;

    protected $profitService;
    protected $logger;

    public function __construct(ProfitCalculatorService $profitService, SerenityLoggerService $logger)
    {
        $this->profitService = $profitService;
        $this->logger = $logger;
    }

    public function index(Request $request)
    {
        try {
            $request->validate([
                'period' => 'required|in:daily,weekly,monthly,yearly,custom',
                'start_date' => 'required_if:period,custom|date',
                'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
            ]);
            
            $query = Profit::with(['product', 'transaction']);
            
            switch ($request->period) {
                case 'daily':
                    $query->whereDate('profit_date', $request->input('date', now()->toDateString()));
                    $title = 'Laporan Laba Harian';
                    break;
                case 'weekly':
                    $query->whereBetween('profit_date', [now()->startOfWeek(), now()->endOfWeek()]);
                    $title = 'Laporan Laba Mingguan';
                    break;
                case 'monthly':
                    $month = $request->input('month', now()->month);
                    $year = $request->input('year', now()->year);
                    $query->whereMonth('profit_date', $month)->whereYear('profit_date', $year);
                    $title = "Laporan Laba Bulanan - " . date('F Y', mktime(0, 0, 0, $month, 1, $year));
                    break;
                case 'yearly':
                    $year = $request->input('year', now()->year);
                    $query->whereYear('profit_date', $year);
                    $title = "Laporan Laba Tahunan - {$year}";
                    break;
                case 'custom':
                    $query->whereBetween('profit_date', [$request->start_date, $request->end_date]);
                    $title = 'Laporan Laba Kustom';
                    break;
            }
            
            $profits = $query->orderBy('profit_date', 'desc')->paginate($request->input('per_page', 10));
            
            $summary = [
                'total_profit' => $query->sum('profit_amount'),
                'egg_profit' => (clone $query)->whereHas('product', fn($q) => $q->where('category', 'egg'))->sum('profit_amount'),
                'rice_profit' => (clone $query)->whereHas('product', fn($q) => $q->where('category', 'rice'))->sum('profit_amount'),
                'total_quantity' => $query->sum('quantity_sold'),
                'average_profit_per_transaction' => $query->count() > 0 ? $query->sum('profit_amount') / $query->count() : 0,
            ];
            
            // Data untuk grafik
            $chartData = $this->getChartData($request);
            
            return $this->success([
                'title' => $title,
                'period' => $request->period,
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary' => $summary,
                'profits' => $profits,
                'chart' => $chartData,
            ], 'Laporan laba berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Profit report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan laba', null, 500);
        }
    }

    private function getChartData(Request $request)
    {
        $chartData = [];
        
        if ($request->period === 'daily') {
            $chartData = Profit::select(DB::raw('HOUR(created_at) as hour'), DB::raw('SUM(profit_amount) as total'))
                ->whereDate('profit_date', $request->input('date', now()->toDateString()))
                ->groupBy('hour')
                ->orderBy('hour')
                ->get();
        } elseif ($request->period === 'weekly') {
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $chartData[] = [
                    'date' => $date,
                    'day' => now()->subDays($i)->format('l'),
                    'profit' => Profit::whereDate('profit_date', $date)->sum('profit_amount'),
                ];
            }
        } elseif ($request->period === 'monthly') {
            $month = $request->input('month', now()->month);
            $year = $request->input('year', now()->year);
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            
            for ($i = 1; $i <= $daysInMonth; $i++) {
                $date = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . str_pad($i, 2, '0', STR_PAD_LEFT);
                $chartData[] = [
                    'date' => $date,
                    'day' => $i,
                    'profit' => Profit::whereDate('profit_date', $date)->sum('profit_amount'),
                ];
            }
        } elseif ($request->period === 'yearly') {
            $year = $request->input('year', now()->year);
            for ($i = 1; $i <= 12; $i++) {
                $chartData[] = [
                    'month' => date('F', mktime(0, 0, 0, $i, 1)),
                    'month_num' => $i,
                    'profit' => Profit::whereMonth('profit_date', $i)->whereYear('profit_date', $year)->sum('profit_amount'),
                ];
            }
        }
        
        return $chartData;
    }
}