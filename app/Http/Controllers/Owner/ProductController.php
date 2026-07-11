<?php

namespace App\Http\Controllers\Owner;

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

    /**
     * Daftar produk berdasarkan kategori (egg/rice)
     * GET /api/owner/products/list
     */
    public function listByCategory(Request $request)
    {
        try {
            $products = Product::orderBy('category')->orderBy('name')->get();

            // Group by kategori
            $byCategory = [
                'egg' => [],
                'rice' => [],
            ];

            foreach ($products as $product) {
                if ($product->category === 'egg') {
                    $byCategory['egg'][] = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'category' => $product->category,
                        'unit' => $product->unit,
                        'selling_price' => (int) $product->selling_price,
                        'selling_price_formatted' => 'Rp ' . number_format($product->selling_price, 0, ',', '.'),
                        'stock' => $product->stock,
                        'min_stock' => $product->min_stock,
                        'supplier' => $product->supplier ? [
                            'id' => $product->supplier->id,
                            'name' => $product->supplier->name,
                        ] : null,
                    ];
                } else {
                    $byCategory['rice'][] = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'category' => $product->category,
                        'unit' => $product->unit,
                        'selling_price' => (int) $product->selling_price,
                        'selling_price_formatted' => 'Rp ' . number_format($product->selling_price, 0, ',', '.'),
                        'stock' => $product->stock,
                        'min_stock' => $product->min_stock,
                        'supplier' => $product->supplier ? [
                            'id' => $product->supplier->id,
                            'name' => $product->supplier->name,
                        ] : null,
                    ];
                }
            }

            // Hitung total stok per kategori
            $totalStockEgg = collect($byCategory['egg'])->sum('stock');
            $totalStockRice = collect($byCategory['rice'])->sum('stock');

            return $this->success([
                'products' => [
                    'egg' => [
                        'name' => 'Telur',
                        'items' => $byCategory['egg'],
                        'total_stock' => $totalStockEgg,
                    ],
                    'rice' => [
                        'name' => 'Beras',
                        'items' => $byCategory['rice'],
                        'total_stock' => $totalStockRice,
                    ],
                ],
            ], 'Daftar produk berhasil dimuat', 200);

        } catch (\Exception $e) {
            $this->logger->error('Get products list error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar produk', null, 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $category = $request->input('category');
            
            $cacheKey = 'owner_products_' . ($category ?? 'all') . '_page_' . ($request->input('page', 1));
            
            $query = Product::with('supplier');
            
            if ($category && in_array($category, ['egg', 'rice'])) {
                $query->where('category', $category);
            }
            
            $products = Cache::remember($cacheKey, 600, function () use ($query, $perPage) {
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
            $cacheKey = 'owner_product_' . $id;
            
            $product = Cache::remember($cacheKey, 3600, function () use ($id) {
                return Product::with('supplier')->findOrFail($id);
            });
            
            return $this->success($product, 'Detail produk berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get product detail error: ' . $e->getMessage());
            return $this->error('Produk tidak ditemukan', null, 404);
        }
    }
}