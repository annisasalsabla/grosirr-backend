<?php

namespace App\Services;

use App\Models\Product;

class BadProductCalculator
{
    /**
     * Konstanta konversi satuan ke unit dasar
     */
    private const UNIT_CONVERSIONS = [
        // Telur
        'tray' => 30,
        'rak' => 30,
        'papan' => 30,
        'butir' => 1,
        'pcs' => 1,
        'pc' => 1,
        'bijir' => 1,
        'btir' => 1,
        
        // Beras
        'karung' => 50,
        'sak' => 50,
        'bag' => 50,
        'kg' => 1,
        'kilogram' => 1,
        'gram' => 0.001,
    ];

    /**
     * Hitung loss amount berdasarkan satuan yang diinput admin
     * 
     * @param Product $product Produk yang rusak
     * @param int $quantity Jumlah kerusakan
     * @param string $unit Satuan yang diinput (butir, tray, kg, karung, dll)
     * @return float Total kerugian
     */
    public static function calculateLossAmount(Product $product, int $quantity, string $unit): float
    {
        $unitLower = strtolower(trim($unit));
        $purchasePrice = (float) $product->purchase_price;
        
        // Cek apakah satuan yang diinput adalah satuan besar (tray/karung)
        $isBulkUnit = in_array($unitLower, ['tray', 'rak', 'papan', 'karung', 'sak', 'bag']);
        
        // Cek apakah satuan yang diinput adalah satuan eceran (butir/kg)
        $isRetailUnit = in_array($unitLower, ['butir', 'pcs', 'pc', 'kg', 'kilogram']);
        
        // Jika satuan besar (tray/karung), harga per unit sudah sesuai
        if ($isBulkUnit) {
            // Langsung kalikan quantity dengan harga per satuan besar
            return $purchasePrice * $quantity;
        }
        
        // Jika satuan eceran (butir/kg), hitung harga per unit eceran
        if ($isRetailUnit) {
            // Tentukan konversi ke satuan besar
            $conversionRate = self::getConversionRate($product->category, $unitLower);
            
            // Harga per unit eceran = Harga grosir / konversi
            $pricePerRetailUnit = $purchasePrice / $conversionRate;
            
            // Total kerugian = jumlah * harga per unit eceran
            return $pricePerRetailUnit * $quantity;
        }
        
        // Jika satuan tidak dikenal, fallback ke harga per satuan besar
        return $purchasePrice * $quantity;
    }

    /**
     * Dapatkan rate konversi berdasarkan kategori produk dan satuan
     * 
     * @param string $category egg / rice
     * @param string $unit butir / kg
     * @return float
     */
    private static function getConversionRate(string $category, string $unit): float
    {
        // Untuk telur: 1 tray = 30 butir
        if ($category === 'egg') {
            return 30;
        }
        
        // Untuk beras: 1 karung = 50 kg
        if ($category === 'rice') {
            return 50;
        }
        
        return 1;
    }

    /**
     * Validasi apakah satuan yang diinput masuk akal untuk produk tertentu
     * 
     * @param string $category egg / rice
     * @param string $unit Satuan yang diinput
     * @return bool
     */
    public static function isValidUnit(string $category, string $unit): bool
    {
        $unitLower = strtolower(trim($unit));
        
        if ($category === 'egg') {
            $validUnits = ['tray', 'rak', 'papan', 'butir', 'pcs', 'pc'];
            return in_array($unitLower, $validUnits);
        }
        
        if ($category === 'rice') {
            $validUnits = ['karung', 'sak', 'bag', 'kg', 'kilogram'];
            return in_array($unitLower, $validUnits);
        }
        
        return false;
    }

    /**
     * Dapatkan label satuan yang direkomendasikan untuk produk
     * 
     * @param string $category egg / rice
     * @return string
     */
    public static function getRecommendedUnit(string $category): string
    {
        if ($category === 'egg') {
            return 'tray (30 butir) atau butir';
        }
        
        if ($category === 'rice') {
            return 'karung (50 kg) atau kg';
        }
        
        return '-';
    }

    /**
     * Format hasil perhitungan untuk ditampilkan
     * 
     * @param Product $product
     * @param int $quantity
     * @param string $unit
     * @return array
     */
    public static function getCalculationDetail(Product $product, int $quantity, string $unit): array
    {
        $unitLower = strtolower(trim($unit));
        $purchasePrice = (float) $product->purchase_price;
        $lossAmount = self::calculateLossAmount($product, $quantity, $unit);
        
        $explanation = '';
        
        if (in_array($unitLower, ['tray', 'rak', 'papan', 'karung', 'sak', 'bag'])) {
            $explanation = "{$quantity} {$unit} × Rp " . number_format($purchasePrice, 0, ',', '.') . " = Rp " . number_format($lossAmount, 0, ',', '.');
        } elseif (in_array($unitLower, ['butir', 'pcs', 'pc'])) {
            $conversion = 30;
            $pricePerUnit = $purchasePrice / $conversion;
            $explanation = "{$quantity} butir × (Rp " . number_format($purchasePrice, 0, ',', '.') . " ÷ 30 butir) = Rp " . number_format($lossAmount, 0, ',', '.');
        } elseif (in_array($unitLower, ['kg', 'kilogram'])) {
            $conversion = 50;
            $pricePerUnit = $purchasePrice / $conversion;
            $explanation = "{$quantity} kg × (Rp " . number_format($purchasePrice, 0, ',', '.') . " ÷ 50 kg) = Rp " . number_format($lossAmount, 0, ',', '.');
        }
        
        return [
            'loss_amount' => $lossAmount,
            'formatted_loss' => 'Rp ' . number_format($lossAmount, 0, ',', '.'),
            'explanation' => $explanation,
            'unit_used' => $unitLower,
            'is_bulk' => in_array($unitLower, ['tray', 'rak', 'papan', 'karung', 'sak', 'bag']),
            'is_retail' => in_array($unitLower, ['butir', 'pcs', 'pc', 'kg', 'kilogram']),
        ];
    }
}