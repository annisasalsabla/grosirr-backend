<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
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
            $category = $request->input('category');
            
            $cacheKey = 'cashier_products_' . ($category ?? 'all') . '_page_' . ($request->input('page', 1));
            
            $query = Product::select('id', 'name', 'category', 'unit', 'selling_price', 'stock', 'min_stock')
                ->where('stock', '>', 0);
            
            if ($category && in_array($category, ['egg', 'rice'])) {
                $query->where('category', $category);
            }
            
            $products = Cache::remember($cacheKey, 300, function () use ($query, $perPage) {
                return $query->orderBy('name')->paginate($perPage);
            });
            
            return $this->success($products, 'Daftar produk berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get products error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar produk', null, 500);
        }
    }

    public function show($id)
    {
        try {
            $product = Product::select('id', 'name', 'category', 'unit', 'selling_price', 'stock', 'min_stock')
                ->findOrFail($id);
            
            return $this->success($product, 'Detail produk berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            return $this->error('Produk tidak ditemukan', null, 404);
        }
    }
}