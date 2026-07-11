<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Supplier;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Supplier Telur
        $supplierTelur = Supplier::create([
            'name' => 'PT Sumber Telur Makmur',
            'address' => 'Jl. Gadut',
            'phone' => '082387356783',
            'product_type' => 'egg',
        ]);
        
        // Supplier Beras
        $supplierBeras = Supplier::create([
            'name' => 'UD Beras Sejahtera',
            'address' => 'Jl. Piai',
            'phone' => '085183026483',
            'product_type' => 'rice',
        ]);
        
        // Produk Telur (satuan: tray)
        Product::create([
            'name' => 'Telur Kecil',
            'category' => 'egg',
            'unit' => 'tray',
            'purchase_price' => 43000, // Harga beli per tray
            'selling_price' => 47000, // Harga jual per tray
            'stock' => 100,
            'min_stock' => 20, // Stok minimum 20 tray
            'supplier_id' => $supplierTelur->id,
        ]);

        Product::create([
            'name' => 'Telur Super',
            'category' => 'egg',
            'unit' => 'tray',
            'purchase_price' => 47000,
            'selling_price' => 52000,
            'stock' => 80,
            'min_stock' => 20, // Stok minimum 15 tray
            'supplier_id' => $supplierTelur->id,
        ]);

        Product::create([
            'name' => 'Telur Jumbo',
            'category' => 'egg',
            'unit' => 'tray',
            'purchase_price' => 52000,
            'selling_price' => 57000,
            'stock' => 50,
            'min_stock' => 20, // Stok minimum 10 tray
            'supplier_id' => $supplierTelur->id,
        ]);

        // Produk Beras (satuan: karung)
        Product::create([
            'name' => 'Beras iR42',
            'category' => 'rice',
            'unit' => 'karung', // 1 karung = 50kg
            'purchase_price' => 160000,
            'selling_price' => 175000,
            'stock' => 30,
            'min_stock' => 20, // Stok minimum 5 karung
            'supplier_id' => $supplierBeras->id,
        ]);
    }
}