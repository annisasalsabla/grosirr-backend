<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Product;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StockController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            
            $cacheKey = 'owner_stocks_page_' . ($request->input('page', 1));
            
            $stocks = Cache::remember($cacheKey, 300, function () use ($perPage) {
                return Stock::with(['product', 'user'])
                    ->orderBy('created_at', 'desc')
                    ->paginate($perPage);
            });
            
            // Ringkasan stok
            $summary = [
                'total_products' => Product::count(),
                'total_stock_items' => Product::sum('stock'),
                'low_stock_count' => Product::whereColumn('stock', '<=', 'min_stock')->count(),
                'out_of_stock_count' => Product::where('stock', 0)->count(),
            ];
            
            return $this->success([
                'stocks' => $stocks,
                'summary' => $summary
            ], 'Data stok berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get stocks error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat data stok', null, 500);
        }
    }

    public function history(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $productId = $request->input('product_id');
            
            $query = Stock::with(['product', 'user']);
            
            if ($productId) {
                $query->where('product_id', $productId);
            }
            
            $history = $query->orderBy('created_at', 'desc')->paginate($perPage);
            
            return $this->success($history, 'Riwayat stok berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get stock history error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat riwayat stok', null, 500);
        }
    }
}