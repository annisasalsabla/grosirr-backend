<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            
            $query = Product::with('supplier');
            
            if ($category && in_array($category, ['egg', 'rice'])) {
                $query->where('category', $category);
            }
            
            $products = $query->orderBy('name')->paginate($perPage);
            
            return $this->success($products, 'Daftar produk berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get products error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar produk', null, 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'category' => 'required|in:egg,rice',
                'unit' => 'required|string|in:tray,butir,kg,karung',
                'purchase_price' => 'required|numeric|gt:0',
                'selling_price' => 'required|numeric|min:0|gt:purchase_price',
                'min_stock' => 'required|integer|min:0',
                'supplier_id' => 'nullable|exists:suppliers,id',
            ]);
            
            $product = Product::create([
                'name' => $request->name,
                'category' => $request->category,
                'unit' => $request->unit,
                'purchase_price' => $request->purchase_price,
                'selling_price' => $request->selling_price,
                'stock' => 0,
                'min_stock' => $request->min_stock,
                'supplier_id' => $request->supplier_id,
            ]);
            
            $this->logger->info('Product created by Admin', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success($product, 'Produk berhasil ditambahkan', 201);
            
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data produk tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Create product error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menambah produk', null, 500);
        }
    }

    public function show($id)
    {
        try {
            $product = Product::with('supplier')->findOrFail($id);
            return $this->success($product, 'Detail produk berhasil dimuat', 200);
        } catch (\Exception $e) {
            return $this->error('Produk tidak ditemukan', null, 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $product = Product::findOrFail($id);
            
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'unit' => 'sometimes|string|in:tray,butir,kg,karung',
                'purchase_price' => 'sometimes|numeric|gt:0',
                'selling_price' => 'sometimes|numeric|min:0',
                'min_stock' => 'sometimes|integer|min:0',
                'supplier_id' => 'nullable|exists:suppliers,id',
            ]);
            
            if ($request->has('selling_price') && $request->has('purchase_price')) {
                if ($request->selling_price <= $request->purchase_price) {
                    return $this->error('Harga jual harus lebih besar dari harga beli', null, 400);
                }
            }
            
            $product->update($request->only([
                'name', 'unit', 'purchase_price', 'selling_price', 'min_stock', 'supplier_id'
            ]));
            
            $this->logger->info('Product updated by Admin', [
                'product_id' => $product->id,
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success($product, 'Produk berhasil diperbarui', 200);
            
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data produk tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Update product error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memperbarui produk', null, 500);
        }
    }

    public function destroy($id, Request $request)
    {
        try {
            $product = Product::findOrFail($id);
            
            // Cek apakah produk pernah digunakan di transaksi
            if ($product->transactionDetails()->exists()) {
                return $this->error('Produk tidak dapat dihapus karena sudah pernah digunakan dalam transaksi', null, 400);
            }
            
            $product->delete();
            
            $this->logger->info('Product deleted by Admin', [
                'product_id' => $id,
                'product_name' => $product->name,
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success(null, 'Produk berhasil dihapus', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Delete product error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menghapus produk', null, 500);
        }
    }
}