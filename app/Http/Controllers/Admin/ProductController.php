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
                'unit' => 'required|string|in:tray,karung',
                'selling_price' => 'required|numeric|gt:0',
                'min_stock' => 'required|integer|min:0',
                'supplier_id' => 'nullable|exists:suppliers,id',
                'image' => 'nullable|image|max:2048',
            ]);
            
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = \App\Helpers\CloudinaryHelper::upload($request->file('image'), 'products');
            }
            
            $product = Product::create([
                'name' => $request->name,
                'category' => $request->category,
                'unit' => $request->unit,
                'purchase_price' => 0, // Hardcode 0
                'selling_price' => $request->selling_price,
                'stock' => 0,
                'min_stock' => $request->min_stock,
                'supplier_id' => $request->supplier_id,
                'image_url' => $imagePath,
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
                'unit' => 'sometimes|string|in:tray,karung',
                'selling_price' => 'sometimes|numeric|gt:0',
                'min_stock' => 'sometimes|integer|min:0',
                'supplier_id' => 'nullable|exists:suppliers,id',
                'image' => 'nullable|image|max:2048',
            ]);
            
            if ($request->has('selling_price')) {
                if ($request->selling_price <= $product->purchase_price) {
                    return $this->error('Harga jual harus lebih besar dari harga beli saat ini (Rp ' . number_format($product->purchase_price, 0, ',', '.') . ')', null, 400);
                }
            }
            
            $dataToUpdate = $request->only([
                'name', 'unit', 'selling_price', 'min_stock', 'supplier_id'
            ]);
            
            $oldImagePath = null;
            if ($request->hasFile('image')) {
                $newImagePath = \App\Helpers\CloudinaryHelper::upload($request->file('image'), 'products');
                
                $oldImagePath = $product->image_url;
                $dataToUpdate['image_url'] = $newImagePath;
            }
            
            $product->update($dataToUpdate);
            
            if (isset($oldImagePath) && $oldImagePath) {
                \App\Helpers\CloudinaryHelper::delete($oldImagePath);
            }
            
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
            
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23000) {
                return $this->error('Produk ini tidak bisa dihapus karena masih memiliki riwayat laporan barang rusak terkait.', null, 422);
            }
            $this->logger->error('Delete product query error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan database saat menghapus produk', null, 500);
        } catch (\Exception $e) {
            $this->logger->error('Delete product error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat menghapus produk', null, 500);
        }
    }
}