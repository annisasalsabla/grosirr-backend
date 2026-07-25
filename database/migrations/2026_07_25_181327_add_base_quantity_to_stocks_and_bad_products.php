<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Stock;
use App\Models\BadProduct;
use App\Helpers\UnitConversionHelper;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah kolom base_quantity (sebelah kanan kolom quantity)
        if (!Schema::hasColumn('stocks', 'base_quantity')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->integer('base_quantity')->default(0)->after('quantity');
            });
        }
        
        if (!Schema::hasColumn('bad_products', 'base_quantity')) {
            Schema::table('bad_products', function (Blueprint $table) {
                $table->integer('base_quantity')->default(0)->after('quantity');
            });
        }

        // 2 & 3. Bungkus seluruh operasi update di dalam Database Transaction (ACID)
        DB::transaction(function () {
            // Data Migration Massal: Backfill default base_quantity = quantity untuk semua data (asumsi 1:1 grosir)
            DB::statement('UPDATE stocks SET base_quantity = quantity');
            DB::statement('UPDATE bad_products SET base_quantity = quantity');

            // Data Migration Bedah Mikro: Koreksi khusus data pecahan (butir/pcs) yang tidak 1:1

            // Koreksi BadProducts (unit 'butir')
            $badProductsToFix = BadProduct::with('product')->where('unit', 'butir')->get();
            foreach ($badProductsToFix as $bp) {
                if ($bp->product) {
                    $rawConverted = UnitConversionHelper::toBaseUnitQuantity($bp->product->category, $bp->unit, $bp->quantity);
                    $bp->base_quantity = (int) ceil($rawConverted);
                    $bp->save();
                }
            }

            // Koreksi Stock ID 159 (Laporan 5 butir saat testing)
            $stock159 = Stock::with('product')->find(159);
            if ($stock159 && $stock159->product) {
                $rawConverted = UnitConversionHelper::toBaseUnitQuantity($stock159->product->category, 'butir', $stock159->quantity);
                $stock159->base_quantity = (int) ceil($rawConverted);
                $stock159->save();
            }

            // Koreksi Stock ID 109 (Laporan lawas 1 pcs telur)
            // Menggunakan fungsi UnitConversionHelper tanpa hardcode /30, mensubstitusi 'pcs' ke 'butir' (eceran)
            $stock109 = Stock::with('product')->find(109);
            if ($stock109 && $stock109->product) {
                $rawConverted = UnitConversionHelper::toBaseUnitQuantity($stock109->product->category, 'butir', $stock109->quantity);
                $stock109->base_quantity = (int) ceil($rawConverted);
                $stock109->save();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('stocks', 'base_quantity')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->dropColumn('base_quantity');
            });
        }
        
        if (Schema::hasColumn('bad_products', 'base_quantity')) {
            Schema::table('bad_products', function (Blueprint $table) {
                $table->dropColumn('base_quantity');
            });
        }
    }
};
