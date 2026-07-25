<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\BadProduct;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;

class BadProductReportController extends Controller
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

            $badProducts = BadProduct::with(['product', 'product.supplier', 'reportedBy'])
                ->orderBy('incident_date', 'desc')
                ->paginate($perPage);

            $summary = [
                'total_quantity' => BadProduct::sum('base_quantity'),
                'total_loss' => BadProduct::sum('loss_amount'),
                'total_loss_formatted' => 'Rp ' . number_format(BadProduct::sum('loss_amount'), 0, ',', '.'),
                'monthly_quantity' => BadProduct::whereMonth('incident_date', now()->month)->sum('base_quantity'),
                'monthly_loss' => BadProduct::whereMonth('incident_date', now()->month)->sum('loss_amount'),
                'reported_count' => BadProduct::where('reported_to_supplier', true)->count(),
                'unreported_count' => BadProduct::where('reported_to_supplier', false)->count(),
                'by_reason' => BadProduct::select('damage_reason', \DB::raw('SUM(base_quantity) as total_quantity'))
                    ->groupBy('damage_reason')
                    ->get(),
            ];

            // List product dan supplier
            $productList = BadProduct::with('product.supplier')
                ->get()
                ->groupBy('product_id')
                ->map(function ($items, $productId) {
                    $firstItem = $items->first();
                    return [
                        'product_id' => $productId,
                        'product_name' => $firstItem->product->name,
                        'supplier_name' => $firstItem->product->supplier->name ?? 'Unknown',
                        'total_quantity' => $items->sum('base_quantity'),
                        'total_loss' => $items->sum('loss_amount'),
                    ];
                })
                ->values();

            return $this->success([
                'bad_products' => $badProducts,
                'summary' => $summary,
                'by_product' => $productList
            ], 'Laporan barang rusak berhasil dimuat', 200);

        } catch (\Exception $e) {
            $this->logger->error('Bad product report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan barang rusak', null, 500);
        }
    }
}