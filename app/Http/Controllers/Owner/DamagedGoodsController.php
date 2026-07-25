<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\BadProduct;
use App\Models\Product;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

/**
 * Pemantauan Barang Rusak - Real-time untuk Owner
 * Bukan laporan/report, hanya lihat kondisi saat ini
 */
class DamagedGoodsController extends Controller
{

    use ApiResponseTrait;

    /**
     * Pemantauan Barang Rusak
     * GET /owner/damaged-goods
     */
    public function index(Request $request)
    {
        try {
            // Ambil semua barang rusak (semua status, karena ini pemantauan)
            $damagedGoods = BadProduct::with(['product', 'product.supplier'])
                ->orderBy('tanggal_kejadian', 'desc')
                ->get();

            // Format list barang rusak terlebih dahulu untuk mendapatkan sisa_nilai
            $list = $damagedGoods->map(function ($item) {
                // FIX Poin 2: Gunakan loss_amount asli yang tersimpan di database saat kejadian (historis)
                // agar nilainya tidak melenceng/berubah meski harga beli (purchase_price) produk naik.
                $lossPerItem = $item->loss_amount;
                
                // purchase_price tetap dikirim hanya sebagai info referensi visual harga saat ini
                $purchasePrice = $item->product->purchase_price ?? 0;

                // Hitung status kompensasi & sisa nilai (reuse logika Admin)
                $compensationState = BadProduct::calculateCompensationState($item);

                // FIX Poin 1: Penambahan penanda kategori di nama supplier (epan -> epan (Beras))
                $sup = $item->product->supplier ?? null;
                $catLabel = '';
                if ($sup && $sup->product_type) {
                    $catLabel = $sup->product_type === 'egg' ? 'Telur' : ($sup->product_type === 'rice' ? 'Beras' : ucfirst($sup->product_type));
                }
                $supplierName = $sup ? $sup->name . ($catLabel ? " ($catLabel)" : '') : 'Unknown';

                return [
                    'product_name'            => $item->product->name ?? 'Unknown',
                    'category'                => $item->product->category ?? 'unknown',
                    'supplier_name'           => $supplierName, // Sudah pakai variabel yang mengandung (Telur)/(Beras)
                    'quantity'                => $item->quantity,
                    'unit'                    => $item->unit,
                    'purchase_price'          => (float) $purchasePrice,
                    'purchase_price_formatted'=> 'Rp ' . number_format($purchasePrice, 0, ',', '.'),
                    'total_loss'              => (float) $lossPerItem, // Sesuai snapshot database
                    'total_loss_formatted'    => 'Rp ' . number_format($lossPerItem, 0, ',', '.'),
                    'incident_date'           => $item->tanggal_kejadian?->format('d/m/Y'),
                    'damage_reason'           => $item->damage_reason,
                    'notes'                   => $item->damage_reason,
                    'calculated_status'       => $compensationState['status'],
                    'sisa_nilai'              => (float) $compensationState['sisa_nilai'],
                    'sisa_nilai_formatted'    => 'Rp ' . number_format($compensationState['sisa_nilai'], 0, ',', '.'),
                ];
            });

            // Filter list: HANYA yang BUKAN selesai (belum_diganti/diganti_sebagian).
            // Urutkan berdasar urutan asli (tanggal_kejadian desc dari query).
            $activeList = $list->where('calculated_status', '!=', 'selesai')->values();

            // Hitung total item rusak (HANYA item aktif)
            $totalItems = $activeList->sum('base_quantity');
            $eggItems = $activeList->where('category', 'egg')->sum('base_quantity');
            $riceItems = $activeList->where('category', 'rice')->sum('base_quantity');

            // Hitung total laporan (COUNT dari items)
            $totalReports = $activeList->count();
            $eggReports = $activeList->where('category', 'egg')->count();
            $riceReports = $activeList->where('category', 'rice')->count();

            // Hitung total kerugian berdasarkan SISA NILAI, EXCLUDE selesai
            $totalLoss = $activeList->sum('sisa_nilai');
            $eggLoss = $activeList->where('category', 'egg')->sum('sisa_nilai');
            $riceLoss = $activeList->where('category', 'rice')->sum('sisa_nilai');

            return $this->success([
                'summary' => [
                    'total_kerugian' => (float) $totalLoss,
                    'total_kerugian_formatted' => 'Rp ' . number_format($totalLoss, 0, ',', '.'),
                    'total_item' => $totalItems,
                    'total_laporan' => $totalReports,
                ],
                'by_category' => [
                    'all' => [
                        'name' => 'Semua Produk',
                        'total_kerugian' => (float) $totalLoss,
                        'total_kerugian_formatted' => 'Rp ' . number_format($totalLoss, 0, ',', '.'),
                        'total_item' => $totalItems,
                        'total_laporan' => $totalReports,
                    ],
                    'egg' => [
                        'name' => 'Telur',
                        'total_kerugian' => (float) $eggLoss,
                        'total_kerugian_formatted' => 'Rp ' . number_format($eggLoss, 0, ',', '.'),
                        'total_item' => (int) $eggItems,
                        'total_laporan' => $eggReports,
                    ],
                    'rice' => [
                        'name' => 'Beras',
                        'total_kerugian' => (float) $riceLoss,
                        'total_kerugian_formatted' => 'Rp ' . number_format($riceLoss, 0, ',', '.'),
                        'total_item' => (int) $riceItems,
                        'total_laporan' => $riceReports,
                    ],
                ],
                'items' => $activeList
            ], 'Pemantauan barang rusak berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }
}