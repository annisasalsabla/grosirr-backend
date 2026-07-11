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
                $purchasePrice = $item->product->purchase_price ?? 0;
                $lossPerItem = $purchasePrice * $item->quantity;

                // Hitung status kompensasi & sisa nilai (reuse logika Admin)
                $compensationState = BadProduct::calculateCompensationState($item);

                return [
                    'product_name'            => $item->product->name ?? 'Unknown',
                    'category'                => $item->product->category ?? 'unknown',
                    'supplier_name'           => $item->product->supplier->name ?? 'Unknown',
                    'quantity'                => $item->quantity,
                    'unit'                    => $item->unit,
                    'purchase_price'          => (float) $purchasePrice,
                    'purchase_price_formatted'=> 'Rp ' . number_format($purchasePrice, 0, ',', '.'),
                    'total_loss'              => (float) $lossPerItem,
                    'total_loss_formatted'    => 'Rp ' . number_format($lossPerItem, 0, ',', '.'),
                    'incident_date'           => $item->tanggal_kejadian?->format('d/m/Y'),
                    'damage_reason'           => $item->damage_reason,
                    'notes'                   => $item->damage_reason,
                    'calculated_status'       => $compensationState['status'],
                    'sisa_nilai'              => (float) $compensationState['sisa_nilai'],
                    'sisa_nilai_formatted'    => 'Rp ' . number_format($compensationState['sisa_nilai'], 0, ',', '.'),
                ];
            });

            // Urutkan list: yang BUKAN selesai (belum_diganti/diganti_sebagian) di atas, selesai di bawah.
            // Secondary sort tetap berdasar urutan asli (tanggal_kejadian desc dari query).
            $list = $list->sortBy(function ($item) {
                return $item['calculated_status'] === 'selesai' ? 1 : 0;
            })->values();

            // Hitung total item rusak (tetap hitung semua item)
            $totalItems = $damagedGoods->sum('quantity');
            $eggItems = $damagedGoods->filter(fn($item) => $item->product->category === 'egg')->sum('quantity');
            $riceItems = $damagedGoods->filter(fn($item) => $item->product->category === 'rice')->sum('quantity');

            // Hitung total kerugian berdasarkan SISA NILAI (bukan loss awal)
            $totalLoss = $list->sum('sisa_nilai');
            $eggLoss = $list->where('category', 'egg')->sum('sisa_nilai');
            $riceLoss = $list->where('category', 'rice')->sum('sisa_nilai');

            return $this->success([
                'summary' => [
                    'total_kerugian' => (float) $totalLoss,
                    'total_kerugian_formatted' => 'Rp ' . number_format($totalLoss, 0, ',', '.'),
                    'total_item' => $totalItems,
                ],
                'by_category' => [
                    'all' => [
                        'name' => 'Semua Produk',
                        'total_kerugian' => (float) $totalLoss,
                        'total_kerugian_formatted' => 'Rp ' . number_format($totalLoss, 0, ',', '.'),
                        'total_item' => $totalItems,
                    ],
                    'egg' => [
                        'name' => 'Telur',
                        'total_kerugian' => (float) $eggLoss,
                        'total_kerugian_formatted' => 'Rp ' . number_format($eggLoss, 0, ',', '.'),
                        'total_item' => (int) $eggItems,
                    ],
                    'rice' => [
                        'name' => 'Beras',
                        'total_kerugian' => (float) $riceLoss,
                        'total_kerugian_formatted' => 'Rp ' . number_format($riceLoss, 0, ',', '.'),
                        'total_item' => (int) $riceItems,
                    ],
                ],
                'items' => $list
            ], 'Pemantauan barang rusak berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }
}