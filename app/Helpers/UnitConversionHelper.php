<?php

namespace App\Helpers;

class UnitConversionHelper
{
    private const UNIT_CONVERSIONS = [
        // Konversi ke butir (telur)
        'tray' => 30,
        'butir' => 1,
        
        // Konversi ke karung (beras)
        'karung' => 1,
    ];

    /**
     * Konversi quantity (berbagai satuan) menjadi quantity base unit (grosir)
     * Contoh telur: 30 butir -> 1 tray, 15 butir -> 0.5 tray, 2 tray -> 2 tray
     */
    public static function toBaseUnitQuantity(string $category, string $unit, float $quantity): float
    {
        $unitLower = strtolower(trim($unit));
        $conversionRate = self::UNIT_CONVERSIONS[$unitLower] ?? null;
        
        if (!$conversionRate) {
            return $quantity; // Fallback jika satuan tidak dikenali
        }
        
        if ($category === 'egg') {
            // Base unit telur adalah tray, nilai per-tray adalah 30 butir
            return ($quantity * $conversionRate) / 30;
        }
        
        if ($category === 'rice') {
            // Base unit beras adalah karung, nilai per-karung sementara 1
            return ($quantity * $conversionRate) / 1;
        }

        return $quantity;
    }
}
